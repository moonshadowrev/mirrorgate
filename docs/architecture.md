# Architecture

This document describes how MirrorGate is built and how a request travels through it. It is intended for contributors and for operators who want to understand the behaviour behind the settings.

Contents:

- [Design goals](#design-goals)
- [Components](#components)
- [Configuration pipeline](#configuration-pipeline)
- [Boot sequence](#boot-sequence)
- [Request lifecycle](#request-lifecycle)
- [Response handling: buffer, stream or discard](#response-handling-buffer-stream-or-discard)
- [Failure handling](#failure-handling)
- [Caching](#caching)
- [Rewriting](#rewriting)
- [Security architecture](#security-architecture)
- [Storage layout](#storage-layout)
- [Shared hosting runtime model](#shared-hosting-runtime-model)
- [The daemon](#the-daemon)
- [Quality tooling and tests](#quality-tooling-and-tests)
- [Compatibility rules for PHP 8.3 to 8.5](#compatibility-rules-for-php-83-to-85)

## Design goals

1. **Runs where PHP runs.** Upload-only installation on shared hosting. The only required extensions are `curl`, `json` and `mbstring`. No Composer packages are needed at runtime; `src/autoload.php` is a small PSR-4 autoloader for the `MirrorGate\` namespace, and there is a hand-written `.env` parser.
2. **Configuration only.** Behaviour is controlled by environment variables (`.env`); there is no code to edit.
3. **Safe by default.** The upstream destination comes from configuration, never from the request. Shared state is anonymous. Secrets are kept out of the document root, logs and error pages.
4. **Honest about limits.** PHP under a web server cannot hold WebSockets, gRPC, TCP or UDP, and shared hosting limits workers and process time. MirrorGate degrades instead of failing, and a separate daemon exists for the socket protocols.
5. **One instance per mirror host.** Multiple mirrors are multiple independent instances that agree on sibling host names through `HOST_MAP`.

## Components

```
public/index.php                 front controller (web), MIRRORGATE_ROOT / MIRRORGATE_ENV
bin/mirrorgate                   CLI: check | purge | gc | config:dump | version
bin/mirrorgate-gc                CLI for cron: incremental cache garbage collection
bin/mirrorgate-daemon            CLI: long-running daemon (WS, gRPC, TCP, UDP)

src/
  Bootstrap, Kernel, Container, Version, autoload.php
  Env/        EnvParser, EnvReader                      .env file parsing and typed access
  Config/     ConfigFactory + immutable Config objects  validation of every variable
  Http/       Request, Response, HeaderBag, Router,     request/response model, front-door pieces
              PathNormalizer, BodySpooler, ProxySink,
              ResponseEmitter, Compressor
  Security/   RequestValidator, HostGuard,              request validation, trust decisions,
              ClientIpResolver, RequestHeaderBuilder,   limits, admin
              HeaderSanitizer, IpClassifier,
              AddressFloor, Cidr/CidrList,
              rate limiters, ConcurrencyLimiter,
              AdminAuthenticator, AdminController
  Upstream/   UpstreamClient (curl), OriginGuard,       talking to the origin
              CachingDnsResolver, CircuitBreaker,
              RetryPolicy, ErrorMapper, ...
  Rewrite/    Rewriter + HostMapper, HostReplacer,      content and header rewriting
              HtmlRewriter, CssRewriter, JsonRewriter,
              HeaderRewriter, LocationRewriter,
              CookieRewriter, CspRewriter, ...
  Cache/      HttpCache, CachePolicy, CacheKeyBuilder,  on-disk shared cache
              CacheStore, CacheGc, CacheLock, ...
  Error/      ErrorPage, ErrorRenderer                  friendly error pages
  Log/        FileLogger, NullLogger                    JSON-line logging
  Support/    Hostname, Punycode, Str, FileUtil, Json   shared helpers
  Console/    SelfCheck                                 the `check` command and admin /check
  Daemon/     Daemon, EventLoop, ListenerSpec,          the optional socket daemon
              WebSocketProxy, TcpForwarder,
              GrpcForwarder, UdpForwarder, ...

templates/   error.html, error.fa.html
examples/    nginx.conf, apache-vhost.conf, mirrorgate-daemon.service, demo-origin/
tests/       unit tests, security probes, lint
scripts/     e2e.sh
```

Conventions that hold across the code base: every file starts with `declare(strict_types=1)`; one class, interface or enum per file; classes are `final` except where an interface is the extension point; value objects are `readonly`; exceptions carry HTTP statuses only through `HttpException` and are turned into responses by `Kernel` and the entry scripts only.

### The three entry points

| Entry | Purpose | Notes |
|---|---|---|
| `public/index.php` | The web application | Loads `src/autoload.php` and calls `Bootstrap::run()` |
| `bin/mirrorgate`, `bin/mirrorgate-gc` | Operator tools | Refuse to run unless PHP is on the command line |
| `bin/mirrorgate-daemon` | Socket forwarding | CLI only; also refuses to run under a web SAPI |

### The container

`Container` is a small lazy service locator created per request (or per CLI run). It builds each service on first use from the immutable `Config`: logger, salt, rate limiter, concurrency limiters, circuit breaker, upstream client (DNS cache, origin guard, retry policy), rewriter, cache, error renderer, router, request validator, host guard, client IP resolver, header builder, admin controller and self check. There is no global state beyond PHP's own.

## Configuration pipeline

1. **Locate** the env file: constant `MIRRORGATE_ENV`, then the process variable, then `<root>/.env.php`, then `<root>/.env` (`Bootstrap::resolveEnvFile`).
2. **Read** the file with a size cap (256 KiB) and parse it with `EnvParser` (no variable expansion, no side effects: values never touch `$_ENV`, `$_SERVER` or `putenv`). The first line of the self-protecting `.env.php` is skipped.
3. **Override** with process environment variables for known keys.
4. **Expand** `TRUSTED_PROXIES` entries (`@file:`) and check `CA_BUNDLE`. These are the only file reads besides the env file itself.
5. **Validate** in the pure `ConfigFactory`. It performs no IO and no DNS, collects every problem and throws one `ConfigException` containing `VARIABLE:reason` codes. On success it returns the immutable `Config` tree (`AppConfig`, `OriginConfig`, `MirrorConfig`, `UpstreamConfig`, `RewriteConfig`, `CacheConfig`, `SecurityConfig`, `RateLimitConfig`, `AdminConfig`, `StreamConfig`, `DaemonConfig`).
6. **Check placement**: the env file and `STORAGE_DIR` must not be inside the document root (the secrets-in-docroot refusal).

The configuration is re-read on every request in the web application because PHP does not keep state between requests. For a typical file this is a few microseconds and is covered by PHP's file cache.

## Boot sequence

`Bootstrap::run()`:

1. Disables error display and logging to output, enables `E_ALL`, reserves 32 KiB of memory for out-of-memory handling.
2. Installs an error handler (logs, never prints), an exception handler and a shutdown function. Fatal errors, parse errors and memory exhaustion leave a log line (type, message, file name and line only) and, if nothing has been sent yet, a generic `500` page.
3. Clears `HTTP_PROXY` (httpoxy) and sets `ignore_user_abort(true)`.
4. Loads and validates the configuration. On `ConfigException` it writes the bootstrap log (codes only) and emits the fallback page.
5. Sets the time zone, adjusts the umask to `0027` if the default `0022` is active and no explicit modes are configured, builds the container and the logger.
6. Builds the `Request` from `$_SERVER` (headers from `getallheaders()` when available), rejecting malformed requests with the fallback page.
7. Runs `Kernel::handle()` and then `Kernel::afterResponse()`.

The **fallback renderer** is part of `Bootstrap`, not of `Error/`, because it must work when no configuration exists: a built-in English HTML string with only the status, a fixed message and the request ID, all escaped, with the generated-response security headers.

## Request lifecycle

`Kernel::handle()` runs the following steps and converts any exception into a response through `ErrorRenderer`. Everything not listed here happens in the named class.

1. **Validate** (`RequestValidator`): method, URL length, header count and size, `Host` shape, request framing, `Content-Length` against `MAX_REQUEST_BODY_BYTES`, `Upgrade` (`501` unless `h2c`), `Expect`.
2. **Host guard** (`HostGuard`): `Host` must be a mirror host on an allowed port, otherwise `421`.
3. **Client address** (`ClientIpResolver`): the connecting address, or the forwarded address when the peer is a trusted proxy. Sampled, hashed proxy hints are recorded for the self check.
4. **Loop guard**: `X-MirrorGate-Hop` containing this instance's ID, or eight entries, gives `508`.
5. **Route** (`Router`):
   - the admin prefix, only while the admin is enabled, to `AdminController` (its own auth, no rate limits),
   - `/.well-known/acme-challenge/` to a local file lookup in the document root (or a local `404`),
   - `BLOCKED_PATHS` and `AUTH_PASSTHROUGH_BLOCK` (tested against decoded and parameter-stripped forms) to a local `404`,
   - a service worker request with `SERVICE_WORKER_MODE=block` to the kill script,
   - prefix-mode paths to the matching extra origin host,
   - everything else to the primary origin.
6. **Limits** (non-admin routes): per-client request rate and concurrency, unless the client is exempt. Slots are released after the response.
7. **Request body** (`BodySpooler`): for body-carrying requests, `php://input` is copied in 64 KiB chunks to a temporary stream while counting. Over the limit gives `413`; a count that differs from the declared length gives `413` or `500`; the body is never forwarded truncated. The upstream request always carries our own exact `Content-Length`.
8. **Cache lookup** (`HttpCache`) if the request is eligible: `Fresh` is served at once; stale-while-revalidate is served and refreshed after the response (when the SAPI can detach); expired or missing entries continue to the fetch with the stale entry remembered for revalidation and error fallback. Request coalescing (`tryLockRefresh`, `waitForEntry`) prevents duplicate fetches.
9. **Breaker, global cap, budget**: `CircuitBreaker::allow()` is called exactly once per client request that needs origin I/O (only the Kernel touches the breaker), then the account-wide concurrency slot, then the per-minute origin fetch budget. Any refusal goes to the failure path.
10. **Build the upstream request**: URL from `OriginConfig` plus the normalised path and query (`UpstreamUrlBuilder`), headers from `RequestHeaderBuilder`, then `Rewriter::rewriteRequestHeaders` (maps `Origin` and `Referer` back to the origin). Cache fills are anonymous and allow-listed. The request is **buffered** (the whole response is collected) when it is cache-eligible or likely rewritable, is a `GET`, and has no `Range`; otherwise it is **passthrough** (streamed), with client conditional headers forwarded.
11. **Fetch** (`UpstreamClient`): `OriginGuard` resolves and validates the address, curl connects to the pinned address, and the response head and body are delivered to a `ProxySink` through callbacks. Retries apply to idempotent methods only, before the response starts.
12. **Decide in the sink** (next section), then **finish**: rewrite, store, compress, emit.
13. **Detach and shut down**: after the response is sent, `ResponseEmitter::detach()` closes the client connection when the SAPI supports it, then `afterResponse()` releases slots, runs one queued background refresh, and may run a bounded garbage-collection pass.
14. **Access log** at `info` level.

## Response handling: buffer, stream or discard

`ProxySink::onHeaders()` receives the status and headers and picks a strategy with these rules, in order:

1. Interim (`1xx`) responses never reach the sink.
2. An origin `5xx` that should be replaced (per `ERROR_PAGE_UPSTREAM`, or any of `500/502/503/504` when a usable stale copy exists) is **discarded**; the Kernel then serves the stale copy or an error page.
3. `text/event-stream` with SSE enabled: acquire a stream slot (none free: discard, `503`), **stream** with `X-Accel-Buffering: no`, `Cache-Control: no-cache, no-transform`, heartbeat comments and a duration limit.
4. `HEAD`, `204`, `304`: buffer the (empty) body; never stored.
5. Rewritable content within the size limit: **buffer**, then rewrite. If the size is unknown and grows beyond the limit mid-transfer, the client is not yet committed, so the Kernel re-fetches once in passthrough mode and the response is reported as `BYPASS`.
6. Cacheable content that fits `CACHE_MAX_OBJECT_BYTES` and passes the policy: **buffer**, then store.
7. Everything else: **stream** (passthrough) with sanitised, rewritten headers; `Content-Length` is kept only when the body is not decoded, so truncation stays visible.

For buffered responses the Kernel runs: error handling, `304` revalidation, stale-on-origin-error, decoding of encodings the origin applied despite `identity`, body rewriting, header rewriting (which may reject unsafe redirects with `502`), a weak ETag from the rewritten body, cache storing (GET only, rewritten identity body without ETag), client conditional handling (`304`), optional gzip, and emission with the cache headers.

## Failure handling

`ErrorMapper` converts internal error kinds to statuses:

| Kind | Status | Counts for the breaker |
|---|---|---|
| connect timeout, total timeout | 504 | yes |
| DNS, connect, TLS, reset, partial transfer, protocol error | 502 | yes |
| SSRF blocked, redirect limit, unsupported, response too large, unknown | 502 | no |
| circuit open | 503 (with `Retry-After`) | no |
| request body too large | 413 | no |
| client aborted | 499 (nothing sent) | no |

The order of resolution for a failure is: **serve a usable stale entry** (for any `5xx` outcome, including those MirrorGate generates itself such as an open breaker or the concurrency cap, but never for `4xx`), otherwise **render the friendly page** with `Retry-After` for `503`. A failure after streaming has started cannot be turned into a page; the stream ends and the event is logged. Origin `502`, `503` and `504` responses also count as breaker failures.

The **circuit breaker** (`BREAKER_*`) stores its state in `STORAGE_DIR/breaker/breaker.json`. After the threshold of failures within the window it opens for the cooldown, which doubles after failed probes up to the maximum. In the half-open state exactly one probe request is allowed (guarded by a file lock). Because only the Kernel calls `allow()`, the probe is consumed once per client request.

Retries (`ORIGIN_RETRIES`) apply to `GET`, `HEAD` and `OPTIONS` for DNS, connect, connect-timeout, TLS and reset errors, only before the response has begun, within 60 percent of the total timeout, with exponential back-off and jitter.

## Caching

The cache is a filesystem store of one file per object plus small index files, keyed by SHA-256 hashes. The full rules (what is stored, freshness, stale handling, purging) are in [caching.md](caching.md). Architecturally relevant points:

- `CacheKeyBuilder` derives a primary hash (instance, purge generation, normalised path and query) and a variant hash (the rewrite fingerprint plus the values of `CACHE_VARY_EXTRA` headers).
- `CachePolicy` is the single place that decides request eligibility, storability, and freshness windows.
- `CacheStore` does path derivation, atomic writes, index maintenance, usage accounting and admission control; `CacheGc` is the incremental collector; `CacheLock` provides `flock` or `mkdir` locks.
- `HttpCache` is the facade used by the Kernel and the admin controller.
- The rewrite fingerprint (`Rewriter::fingerprint()`) includes the rewriter version constant, so a release that changes rewriting output bumps `Version::REWRITER_VERSION` and automatically invalidates older entries.

## Rewriting

`Rewriter` is the facade. `ContentClassifier` decides what to do for a content type and path; `HostMapper` knows the mapping between origin and mirror hosts (primary host, `HOST_MAP` hosts, prefix-mode hosts); `HostReplacer` is the single-pass regular expression that performs host replacement in all spellings; `UrlMapper` wraps it for URL-valued strings; the per-format rewriters (`HtmlRewriter`, `CssRewriter`, `JsonRewriter`, `XmlRewriter`, `JsRewriter`, `RobotsRewriter`, `SrcsetRewriter`) decide where in a document the replacer is applied. Header work is done by `HeaderRewriter` with `LocationRewriter`, `CookieRewriter`, `CspRewriter`, `LinkHeaderRewriter` and `CorsRewriter`. Details and rules: [rewriting.md](rewriting.md).

Properties that the design guarantees: replacement strings are built from configuration only; a regular-expression failure returns the original input; sequential `str_replace` per host is not used because it can double-replace.

## Security architecture

Trust boundaries:

- **Visitor to MirrorGate**: untrusted. Everything is validated (`PathNormalizer`, `RequestValidator`, `HostGuard`), limited (`Security/` limiters) and sanitised (`RequestHeaderBuilder`, `HeaderSanitizer`) before it can influence anything else.
- **MirrorGate to origin**: the destination is fixed by configuration; `OriginGuard` validates addresses and ports; the connection is pinned and verified. The origin is untrusted as a source of response data: headers are validated and filtered, bodies are size-limited, and nothing from the origin is executed or unserialised.
- **Shared state** (cache, limiters): written only through hashed, bounded structures; content in the cache is anonymous.
- **Operator interface** (admin, CLI): off by default or command-line only; generic failure responses.

[security.md](security.md) lists the controls, the checklist and the residual risks.

## Storage layout

Everything the application writes lives below `STORAGE_DIR` (default `<env dir>/var`), plus the optional `CACHE_DIR` and `CONCURRENCY_SHARED_DIR`:

| Path below `STORAGE_DIR` | Content |
|---|---|
| `cache/<instance>/` | Cache entries, indexes, locks, counters ([caching.md](caching.md#cache-keys-and-fingerprints)) |
| `logs/` | `mirrorgate-<12 hex>.log` (and `.1`), `daemon.log` |
| `run/` | `salt` (0600), `proxyhint.json`, daemon `daemon.pid` and `daemon.stop` |
| `ratelimit/` | Fixed set of up to 4096 shard files (`<hhh>.rl`) of the file rate limiter |
| `locks/` | Concurrency lease shards (`<scope>/<hh>.cl`) |
| `breaker/` | `breaker.json`, `probe.lock` |
| `dns/` | Validated DNS answers |

`FileUtil::ensureDir` writes a deny-all `.htaccess` and an empty `index.html` into every directory it creates, and all writes go through a temporary file in the same directory followed by `rename`. State that is shared between processes is protected with `flock`, and all bounded structures cap their size, so a flood of unique clients or URLs cannot grow the disk use without limit.

## Shared hosting runtime model

The code does not assume a particular SAPI and feature-detects what it uses.

- **Workers.** Concurrency is limited by the PHP worker pool of the account. The limiters (`RATE_LIMIT_MAX_CONCURRENT`, `GLOBAL_MAX_CONCURRENT`, `MAX_CONCURRENT_STREAMS`) answer overload quickly instead of letting requests queue, and never sleep a worker as a penalty.
- **Detaching.** After sending a response, `fastcgi_finish_request()` or `litespeed_finish_request()` (when present) lets the process continue with background work: the stale-while-revalidate refresh and garbage collection. Without them, stale-while-revalidate entries are handled as expired and no hidden work follows the response.
- **Time limits.** `set_time_limit()` is used only for streaming and background paths and only if callable. `ResponseEmitter::runtimeCeiling()` computes how long a request may safely run (unbounded when `set_time_limit()` works outside LiteSpeed; otherwise `max_execution_time - 5`, at most 25 seconds on LiteSpeed). Upstream timeouts, SSE duration and background refresh time are clamped to it.
- **Functions that may be disabled**: `set_time_limit`, `ignore_user_abort`, `fastcgi_finish_request`, `litespeed_finish_request`, `ini_set` and `flush` are always probed before use.
- **Output.** Streaming disables output buffering layers, `zlib.output_compression` (when `ini_set` works) and implicit flush limits, and writes with an explicit `flush()` after each chunk.

## The daemon

The daemon is a separate program that shares `Config`, `Bootstrap::loadConfig`, the IP classifier and the logger with the web application. Components:

- `Daemon` supervises: parses and validates listeners, takes the PID file lock, warms target DNS, starts forwarders, installs signal handlers, runs timers (stop-file polling, DNS refresh, memory check, GC) and drains on shutdown.
- `EventLoop` is a `stream_select()` loop with a 200 ms tick and timers; all sockets are non-blocking.
- `Acceptor` handles bind, accept, admission through `ConnectionLimiter` (global, per listener and per client address, with a descriptor budget and fair sharing between listeners), PROXY protocol v1, optional listener TLS, and idle sweeping.
- `Session` and `Connection` implement the two-sided pipe with back-pressure (high-water marks per direction).
- `WebSocketProxy` with `WsHandshake` for the validated upgrade; `TcpForwarder`; `GrpcForwarder` (a TCP forwarder specialised for HTTP/2); `UdpForwarder` for datagram sessions.
- `TargetResolver` enforces `DAEMON_TARGET_ALLOWLIST` and the address rules for every target.

See [daemon.md](daemon.md) for operation and limits.

## Quality tooling and tests

| Tool | Command | What it covers |
|---|---|---|
| Dependency-free unit runner | `php tests/run.php` | The unit test suites without Composer (699 tests at the time of writing) |
| PHPUnit 11 | `composer test` | The same suites under PHPUnit (dev dependency only) |
| PHPStan level 8 | `composer stan` | Static analysis of `src/`, `public/`, `bin/` |
| Lint | `composer lint` | `php -l` on every PHP file and `tests/Lint/check-cli-guards.php`, which verifies that every non-public entry script refuses to run under a web SAPI |
| End-to-end | `bash scripts/e2e.sh` | Starts the demo origin and a mirror on random local ports with PHP's built-in server and asserts rewriting, cookies, redirects, cache states, stale-if-error, error pages, SSE, SSRF and request hardening, admin authentication and logging (104 assertions) |
| Security probes | `php tests/Security/security-probe.php` | Raw request probes (CRLF, smuggling, Host spoofing, traversal, secrets exposure) |
| Daemon self-test | `php tools/daemon-selftest.php` | Daemon against local echo servers (handshake validation, relay, limits, shutdown) |

The unit and end-to-end suites were run on PHP 8.3, 8.4 and 8.5 during development of this documentation. Integration with a specific web server stack (nginx or Apache with PHP-FPM, LiteSpeed) is not part of the automated suites.

## Compatibility rules for PHP 8.3 to 8.5

The code follows these rules to stay free of deprecations on current PHP versions:

- Explicit nullable types (`?string`), no dynamic properties, no `${var}` interpolation, no `utf8_encode`/`utf8_decode`, no `Serializable`, no `unserialize()`.
- No `curl_close()`, `curl_share_close()` or `curl_multi_close()` (deprecated in 8.5, no-ops since 8.0); handles are released by garbage collection.
- The IDNA conversion always passes `INTL_IDNA_VARIANT_UTS46` explicitly; a pure PHP Punycode encoder is used without `ext-intl`.
- Optional parameters come after required ones, only canonical casts are used, and `E_STRICT` is not referenced.
- No `eval`, `exec`, `shell_exec`, `proc_open`, `popen`, `passthru`, `system` or backticks in `src/`, `public/` and `bin/`; `pcntl_*` and `posix_*` are used by the daemon only behind `function_exists`.
- Functions that can be disabled by a host are feature-detected.

Run the checks yourself with `composer check` (needs the dev dependencies) or, without Composer, `php tests/run.php` and `bash scripts/e2e.sh` on each PHP version you intend to deploy.
