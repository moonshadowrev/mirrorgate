# Security

A reverse proxy that is reachable from the whole Internet and talks to another server on request is a sensitive piece of software. This page states what MirrorGate protects against, what it cannot protect against, and what you must do yourself. It describes the behaviour of the code. Most of the controls named here are exercised by the repository's tests (`php tests/run.php`, `php tests/Security/security-probe.php`, `bash scripts/e2e.sh`); the tests do not replace your own review of a deployment.

Contents:

- [The mirror operator sees everything](#the-mirror-operator-sees-everything)
- [Threat model](#threat-model)
- [Request side controls](#request-side-controls)
- [Upstream connections and SSRF](#upstream-connections-and-ssrf)
- [Response side controls](#response-side-controls)
- [Cache poisoning and cache privacy](#cache-poisoning-and-cache-privacy)
- [Admin endpoint](#admin-endpoint)
- [Abuse limits](#abuse-limits)
- [Secrets, files and logs](#secrets-files-and-logs)
- [Hardening checklist](#hardening-checklist)
- [Reporting a vulnerability](#reporting-a-vulnerability)

## The mirror operator sees everything

MirrorGate terminates the visitor's TLS connection on your server and opens a new connection to the origin. By design:

- Every cookie, `Authorization` header, form field, API token and password typed into a mirrored page passes through your server in clear text. `FORWARD_COOKIES` and `FORWARD_AUTHORIZATION` default to `true`, because without them logins on the origin cannot work.
- Anyone who can read your server, your PHP process, your logs or your backups can read those secrets.
- On shared hosting with Apache `mod_php` and no per-account isolation, other accounts on the same server may read your files, including `.env`, through their own PHP. `bin/mirrorgate check` warns about this situation (`php.sapi_isolation`).
- Visitors cannot tell that they are not talking to the origin. If you run a mirror of a site you do not own, they may enter credentials into a copy that you control. This is the nature of a mirror; it is also what a phishing page looks like.

Therefore:

1. **Do not mirror authenticated applications** unless you are the owner of both sides and your visitors know it.
2. If the origin has a login, block its endpoints so credentials never travel through the mirror:

   ```ini
   AUTH_PASSTHROUGH_BLOCK=/login*,/oauth/*,/api/auth/*,/signin*,/account/*
   ```

   Matching paths are answered locally with a `404` and never sent to the origin. `bin/mirrorgate check` warns (`privacy.credentials`) when cookies or authorization headers are forwarded and no blocked paths are configured.
3. For public, anonymous content set `FORWARD_COOKIES=false` and `FORWARD_AUTHORIZATION=false`.
4. Use `CACHE_COOKIE_MODE=bypass` for sites that vary by cookie.
5. Respect the origin's terms and copyright. Choose `SEO_MODE=canonical-origin` or `noindex` if the mirror is for access only (see [rewriting.md](rewriting.md#seo-modes)).

## Threat model

| Attacker | Goal | Main controls |
|---|---|---|
| Any visitor | Make the server connect to internal or arbitrary hosts (SSRF) | Upstream URL built from configuration only; address classification; DNS pinning; allow lists; redirects never followed |
| Any visitor | Smuggle or split requests, inject headers | Strict request validation; header values cannot contain CR, LF or NUL; own `Content-Length`; hop-by-hop removal |
| Any visitor | Poison the shared cache, or read other visitors' data from it | Anonymous, allow-listed cache fills; strict storable rules; key fingerprint; no personalised responses stored |
| Any visitor | Redirect users elsewhere, inject script through rewriting | Replacement text from configuration only; `Location` validation; content types preserved; no script injection |
| Any visitor | Read `.env`, logs, cache files | Placement outside the docroot; boot refusal; deny files; unguessable file names; hashed storage paths |
| Any visitor | Take over the admin endpoint | Disabled by default; long random token; constant-time compare; generic `404` for everything wrong; HTTPS and IP allow list |
| Any visitor | Exhaust workers, disk or bandwidth | Body, header, URL, stream and redirect limits; rate limits; concurrency caps; circuit breaker; cache size caps |
| Malicious or compromised origin | Attack the mirror or its visitors | Response header sanitising; body size limits; no execution of origin data; rewritten URLs validated |
| Co-tenants on shared hosting | Read secrets | `.env.php` form, restrictive file modes, `STORAGE_DIR` outside the docroot. Limited by the host's isolation |

Out of scope: vulnerabilities in the origin, in PHP or the web server, DDoS volume attacks, and the content of the origin (a mirror delivers whatever the origin sends, rewritten).

## Request side controls

**Request line and path.** The request target must be in origin form (start with `/`). Absolute-form, authority-form and asterisk-form targets, fragments (`#`), backslashes, spaces and control characters get `400`. Percent-encoded NUL, CR, LF and backslash (`%00`, `%0d`, `%0a`, `%5c`) are refused. The path is normalised: dot segments are resolved after decoding unreserved characters, percent hex is upper-cased, `%2F` stays encoded, there is no double decoding, and a leading run of slashes is collapsed to one, so `//evil.com` can never become a protocol-relative location. The query string passes through unchanged after control characters are rejected.

**Method.** `TRACE` and `CONNECT` are never allowed. Methods not in `ALLOWED_METHODS` get `405` with an `Allow` header; unknown methods get `501`.

**Headers.** Requests with more than `MAX_HEADER_COUNT` headers or a line above `MAX_HEADER_BYTES` get `431`; a URL longer than `MAX_URL_LENGTH` gets `414`. Header names must be valid tokens, values must not contain control characters, a second or comma-separated `Host` gets `400`, and header names containing `_` are dropped (CGI backends map `_` to `-`, which would otherwise let `X_Forwarded_For` bypass a deny list).

**Request smuggling.** A request with both `Content-Length` and `Transfer-Encoding`, differing `Content-Length` values, a non-numeric `Content-Length`, or a `Transfer-Encoding` other than `chunked` is refused (`400` or `501`). These checks apply to the signals PHP can see; web servers normally de-chunk bodies before PHP. The real protection is that MirrorGate never forwards a client's framing: the body is read into a counted temporary stream and the upstream request carries MirrorGate's own exact `Content-Length`. A body that does not match its declared length is never forwarded (`413` or `500`).

**Upgrade and Expect.** `Upgrade` with anything other than `h2c` gets `501` (PHP cannot hold the socket). `Expect` other than `100-continue` gets `417`.

**Host header attacks.** The `Host` must be the host of `MIRROR_URL` or one of `MIRROR_ALIASES`, and its port must be absent, 80, 443 or the port of `MIRROR_URL`; otherwise `421` with a page that does not echo the host. `X-Forwarded-Host`, `X-Host`, `X-Original-URL` and `X-Rewrite-URL` from clients are ignored everywhere. All URLs that MirrorGate generates (rewritten links, cookies, redirects, forwarded-host headers, error pages) come from configuration.

**Loop detection.** The header `X-MirrorGate-Hop` carries the IDs of instances a request passed through. A request that already contains this instance's ID, or has eight entries, gets `508`. `MIRROR_URL` equal to the origin host, or a subdomain of it, is refused at boot.

**Client headers removed before reaching the origin.** Forwarding and identity headers (`Forwarded`, `X-Forwarded-*`, `X-Real-IP`, `X-Client-IP`, `True-Client-IP`, `CF-*`, `X-Original-*`, `X-Rewrite-*`, `X-HTTP-Method-Override`, `X-Middleware-*`, `X-Invoke-*`, `X-Remote-User`, `X-Auth-Request-*` and similar), `Proxy` (httpoxy), `Via`, `Expect`, `Accept-Encoding` and `Host` are removed. `HTTP_PROXY` is cleared at boot. Add your own names with `REQUEST_HEADER_STRIP`.

**Request body integrity.** PHP's `enable_post_data_reading` must be `0`; otherwise PHP consumes form bodies and `php://input` is empty. MirrorGate detects this and answers `500` rather than forward an empty upload. See [troubleshooting.md](troubleshooting.md#uploads-and-forms-arrive-empty).

## Upstream connections and SSRF

The destination of every upstream request is derived from `ORIGIN_URL` (and `EXTRA_ORIGINS` in prefix mode) plus the normalised path and query. Nothing in the client request, including `Host`, absolute-form targets and forwarded headers, can change the host or port that is contacted.

Defence in depth on top of that:

- **Host and port allow lists.** Only the origin host (and `EXTRA_ORIGINS`) may be contacted, only on `ORIGIN_PORTS_ALLOW` plus the origin's port.
- **Address classification.** Before connecting, the host is resolved and **every** answer must be globally routable. If any record is private, the host is refused. Blocked ranges include `0.0.0.0/8`, `10/8`, `100.64/10` (carrier-grade NAT), `127/8`, `169.254/16` (link-local and cloud metadata), `172.16/12`, `192.0.0/24`, `192.0.2/24`, `192.88.99/24`, `192.168/16`, `198.18/15`, `198.51.100/24`, `203.0.113/24`, multicast and reserved `224/4` and `240/4`, plus, for IPv6, everything outside `2000::/3` and the special ranges inside it (for example Teredo and documentation prefixes). IPv4 addresses embedded in IPv4-mapped, NAT64, 6to4 and IPv4-compatible IPv6 forms are judged by the embedded address. Decimal, hex and octal spellings (`2130706433`, `0x7f.1`, `0177.0.0.1`) are refused.
- **A floor that cannot be lowered.** With `ALLOW_PRIVATE_ORIGIN=true` private and loopback addresses become allowed, but the unspecified address, link-local addresses (including `169.254.169.254`), the AWS IPv6 metadata address and IPv6 forms that embed such addresses stay blocked. The same floor applies to daemon targets.
- **DNS pinning.** The validated address is passed to curl with `CURLOPT_RESOLVE`, so DNS cannot be rebound between the check and the connection. Answers are cached under `STORAGE_DIR/dns` for `DNS_TTL`, reused stale for up to `DNS_STALE_TTL` when the resolver fails, and re-validated on every refresh. IPv4 is preferred. `ORIGIN_RESOLVE_IP` can pin the primary host explicitly (and still passes the same checks).
- **No redirects followed.** Redirects from the origin are passed to the visitor with a rewritten `Location`. `CURLOPT_FOLLOWLOCATION` is off and only `http` and `https` are allowed protocols.
- **TLS verification cannot be disabled.** Peer and host verification are always on, with a minimum of TLS 1.2. `CA_BUNDLE` is for hosts with an outdated trust store, not for turning verification off. `bin/mirrorgate check` recommends `CA_BUNDLE` when it detects TLS verification failures.
- **No ambient configuration.** `.netrc` is ignored, credentials are never sent to a different host after redirects, userinfo in URLs is disabled, and `HTTP_PROXY` environment variables and client `Proxy:` headers are ignored. Proxying happens only through `OUTBOUND_PROXY`.
- **With `OUTBOUND_PROXY`, the proxy is the boundary.** No local DNS or address check is possible then; only the host and port allow lists apply. Proxy credentials are masked in `config:dump`, the admin `/check` output and logs. Use `socks5h://` so the proxy resolves names.
- **Bounded transfers.** Timeouts (`ORIGIN_CONNECT_TIMEOUT`, `ORIGIN_TIMEOUT`), a low-speed abort, `MAX_STREAM_BYTES` for streamed bodies, and `REWRITE_MAX_BYTES` and the memory guard for buffered ones.
- **Origin response headers are untrusted.** Lines with invalid names or control characters are dropped and logged by name only; obsolete line folding fails the transfer. Hop-by-hop headers (and `Connection`-named tokens) are removed, but a `Connection` token can never strip a protected header.

## Response side controls

**Rewriting is safe by construction.** Replacement strings come only from validated configuration; request data never reaches them. Lookalike hosts and e-mail addresses are left untouched. Content types are preserved, and nothing is injected into pages except, with `SEO_MODE=noindex`, one constant meta tag. See [rewriting.md](rewriting.md).

**Redirect safety.** `Location`, `Content-Location` and `Refresh` values with control characters or backslashes, or with the schemes `javascript:`, `data:`, `vbscript:`, `file:`, `blob:`, `about:` or `filesystem:`, are refused (the visitor sees a `502` page). With `REDIRECT_EXTERNAL=block`, redirects to unrelated hosts are refused too. Relative redirects can never turn into `//host` because paths with a leading `//` are collapsed on the way in.

**Header hygiene.** Server banners and origin infrastructure headers are removed (full list in [rewriting.md](rewriting.md#removed-from-responses)). CORS: `Access-Control-Allow-Origin` is mapped, never reflected from the request, and credentials are only allowed together with a specific origin, never with `*`. Cookies are confined: `Domain` is dropped by default, `Secure` is added on HTTPS, and cookie names in MirrorGate's reserved namespace (`mg_*`) are not accepted from either side.

**Service workers.** An origin's service worker can intercept every request of the mirror host. `SERVICE_WORKER_MODE=block` replaces it with a script that unregisters itself and clears caches.

**Error pages.** They contain only the status, a fixed message and the request ID. They never contain origin host names, addresses, curl messages, headers or stack traces. All dynamic values are HTML-escaped, and the pages are sent with `Cache-Control: no-store`, `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: no-referrer` and a Content-Security-Policy that allows no scripts. PHP errors are never displayed: `display_errors` is off from the first line of the front controller, fatal errors are logged and answered with a generic `500`, and `APP_DEBUG` only changes the log level.

## Cache poisoning and cache privacy

- **Anonymous cache fills.** The request that fills the shared cache never carries `Cookie` or `Authorization`, the client address, or any client header except those you explicitly list in `CACHE_VARY_EXTRA` (and then they are part of the key).
- **Strict storable rules.** Responses with `Set-Cookie`, `Cache-Control: private` or `no-store`, `Vary: *` or `Vary: Cookie/Authorization`, an unsupported `Vary` header, a CSP nonce, a status outside `CACHE_STATUSES`, partial content, or truncated transfers are never stored. Requests with `Authorization`, `Range`, a `Service-Worker` header, credentials-style headers (`CACHE_BYPASS_HEADERS`), a cross-site `Origin` or Next.js RSC headers bypass the cache.
- **Key integrity.** The key includes a fingerprint of all rewrite-relevant settings, so a changed configuration never serves stale rewritten content. `Host` and `X-Forwarded-*` are never part of it. Tracking parameters listed in `CACHE_IGNORE_QUERY_PARAMS` are also removed from the upstream query, so a stored response is a pure function of its key.
- **Client cannot force misses.** `Cache-Control: no-cache` from visitors is ignored by default (`CACHE_HONOR_CLIENT_NOCACHE=false`), as are `Pragma` and conditional headers; query variants per path are capped.
- **File safety.** File names are hex digests in sharded directories, so request data never forms a path. Every open checks containment and refuses symlinks. Entries are written atomically, validated on read (format marker, length, CRC32) and discarded on any mismatch. No cache file is ever executed, included or unserialised, and none has a `.php` extension.
- **Metadata privacy.** Request paths are stored in the cache metadata only as keyed hashes, unless `LOG_PATH_MODE=full`.

## Admin endpoint

The admin endpoint is **off** by default. Enable it only if you need purging or remote self-checks. When `ADMIN_ENABLED=false`, the path `ADMIN_PATH_PREFIX` is an ordinary path that is proxied like any other.

Requirements when enabled:

- `ADMIN_TOKEN`: at least 32 characters, no whitespace or control characters, not a placeholder (`change-me`, `example`, `token`, and similar are rejected), not a single repeated character. Generate one with `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`. Boot fails otherwise.
- `MIRROR_URL` must be `https://` (or `ADMIN_ALLOW_HTTP=true`). At runtime, requests that did not arrive over TLS (or via a trusted proxy announcing `X-Forwarded-Proto: https`) get `404`.
- Strongly recommended: `ADMIN_IP_ALLOW` with your own addresses, and `TRUSTED_PROXIES` when a CDN is in front, so the allow list sees real client addresses.
- Optionally change `ADMIN_PATH_PREFIX` (`/[A-Za-z0-9_-]{3,32}`).

Authentication: send the token as `Authorization: Bearer <token>` or, where the web server strips `Authorization`, as `X-MirrorGate-Token: <token>`. Never as a query parameter or cookie. The token is compared in constant time (`hash_equals` on SHA-256 digests).

Every failure looks the same: disabled, wrong path, no TLS, IP not allowed, missing or wrong token all return the generic `404` with no `WWW-Authenticate` header and no hint. Failed attempts are counted per client but never change the answer, never lock a valid token out, and never put a worker to sleep. Admin responses are `Cache-Control: no-store` and never contain configuration values, the origin URL, the token, file system paths or stack traces.

Endpoints (all under `ADMIN_PATH_PREFIX`, default `/__mirrorgate`):

| Method and path | Purpose | Response |
|---|---|---|
| `GET /live` | Liveness | `ok` (plain text) |
| `GET /health` | Summary | JSON: `ok`, PHP major.minor, cache `entries` and `bytes`, breaker `state` and `failures`, time |
| `GET /stats` | Cache and breaker figures | JSON: cache `entries`, `bytes`, `generation`; breaker `state`, `failures`, `openUntil`, `cooldown` |
| `POST /purge` | Remove cache entries | JSON body `{"mode":"url\|prefix\|all","target":"/path","soft":false}` with `Content-Type: application/json`, answered `{"purged": n}` |
| `POST /check` | Full self-check including network probes | JSON `{"checks":[{"name","level","message"}]}` with paths, host names and secrets replaced by `[redacted]` |
| `GET /streamtest` | Five lines 500 ms apart, to detect response buffering | Plain text `chunk n t=<ms>` |

Wrong methods get `405` (`GET`/`HEAD` for reads, `POST` for `/purge` and `/check`), an invalid JSON body `400`, a non-JSON content type `415`, a body above 16 KiB `413`, all only after successful authentication.

Rotate the token by changing `ADMIN_TOKEN` in the env file; the file is read on every request, so the new value applies at once. Disable the endpoint again when you are done if you do not need it.

## Abuse limits

| Limit | Setting | Result when exceeded |
|---|---|---|
| Requests per client IP per window | `RATE_LIMIT_REQUESTS`, `RATE_LIMIT_WINDOW` (default 120 per 60 s) | `429` with `Retry-After` |
| Concurrent requests per client | `RATE_LIMIT_MAX_CONCURRENT` (4) | `429`, `Retry-After: 1` |
| Concurrent origin I/O, account-wide with `CONCURRENCY_SHARED_DIR` | `GLOBAL_MAX_CONCURRENT` (8) | `503`, `Retry-After: 1` (stale content is served instead when available) |
| Origin fetches per minute | `RATE_LIMIT_ORIGIN_FETCHES_PER_MINUTE` (600) | `503` with `Retry-After`, or stale content |
| Concurrent SSE streams | `MAX_CONCURRENT_STREAMS` (3) | `503`, `Retry-After: 5` |
| SSE duration, passthrough bytes | `SSE_MAX_DURATION` (60 s), `MAX_STREAM_BYTES` (100M) | Stream ends |
| Request body | `MAX_REQUEST_BODY_BYTES` (10M) and `post_max_size` | `413` |
| URL length, header count and size | `MAX_URL_LENGTH`, `MAX_HEADER_COUNT`, `MAX_HEADER_BYTES` | `414`, `431` |
| Rewrite buffer | `REWRITE_MAX_BYTES` (8M), decompression bomb guard | Body streamed unmodified |
| Cache | `CACHE_MAX_BYTES`, `CACHE_MAX_ENTRIES`, `CACHE_MAX_OBJECT_BYTES`, `CACHE_MAX_QUERY_VARIANTS` | New entries refused, old ones evicted |
| Origin failures | circuit breaker (`BREAKER_*`) | Fast `503`, or stale content, without a network call |

Rate-limit keys are keyed hashes (HMAC with a per-instance salt) of the client address. IPv6 clients are limited per /64 and IPv4-mapped IPv6 addresses count as IPv4. Raw addresses are not stored. The counters live in APCu when available, otherwise in a fixed set of small shard files under `STORAGE_DIR/ratelimit`, so disk use is bounded. If the limiter storage fails, limits fail open (availability wins) and the self check reports it (`ratelimit.storage`).

If your site is behind a CDN or reverse proxy, set `TRUSTED_PROXIES`; without it every visitor shares the proxy's address and one rate-limit bucket. The forwarded header is only trusted when the connecting address is in the list, the chain is walked from the right, and an invalid entry falls back to the connecting address.

No limit is implemented by sleeping a worker: when a limit is hit the request is answered immediately.

## Secrets, files and logs

**Where secrets live.** The `.env` holds the admin token and optional salt. It must not be reachable by URL. MirrorGate enforces this at boot (see [configuration.md](configuration.md#boot-time-docroot-check)): if the env file or `STORAGE_DIR` is inside the document root it refuses to start, unless the file is the self-protecting `.env.php`, which answers `404` and prints nothing if executed. `.gitignore` excludes `.env`, `.env.*` (except `.env.example`), `var/`, `vendor/` and `*.log`; `.env.example` contains no secret values. Never commit the real env file.

**Defence in depth for files.** `public/` contains no dotfiles except `.htaccess` and `.user.ini`. The Apache rules (and the equivalent `web.config` and nginx example) deny dotfiles, backup suffixes, logs, `composer.*`, other PHP files, and, in the root-shim layout, the project directories. A deny-all `.htaccess` and an empty `index.html` are written into every directory MirrorGate creates under `STORAGE_DIR`. The default log file name carries an unguessable suffix. These measures do not replace correct placement; `.htaccess` is ignored by nginx and by some Apache and LiteSpeed setups.

**Entry scripts.** Everything in `bin/` and `tools/` refuses to run unless PHP is on the command line (otherwise `404`). `tests/Lint/check-cli-guards.php` verifies this. Keep these directories outside the docroot anyway.

**Permissions.** Files are created with `STORAGE_FILE_MODE` (default `0640`), directories with `STORAGE_DIR_MODE` (default `0750`); the generated salt is `0600`. On `mod_php` hosts where PHP runs as a shared user these modes must be loosened, which weakens isolation (see [deployment-shared-hosting.md](deployment-shared-hosting.md#file-permissions)).

**Secrets in processes.** The configuration dump (`bin/mirrorgate config:dump`) masks `token` and `salt` values and proxy credentials. The admin `/check` output removes the token, salt, project and storage paths, the origin URL and host, and the proxy and CA settings from messages.

**Logs.** The log is JSON, one line per event. CR, LF and control characters are stripped from every string (no log injection), and context keys matching `token`, `secret`, `password`, `cookie`, `authorization` or `key` are replaced by `[redacted]`. The access log never contains query strings, `Referer`, cookies, authorization values, request bodies, full client addresses (a 12 hex character keyed hash is logged) or origin host names. Paths are logged according to `LOG_PATH_MODE`, by default only the first segment, because paths often carry tokens such as `/reset/<token>`. A bootstrap log with configuration error **codes** (never values) is written to `<env dir>/var/logs/bootstrap-<hash>.log`.

**Code safety.** The code base contains no `eval`, `unserialize`, shell execution, backticks or `curl_close`, and no dynamic includes of data files. Dependencies: none at runtime.

## Hardening checklist

Before going live:

- [ ] PHP 8.3 or newer, supported by your host.
- [ ] Document root is `public/` (or the layout-B stub); `.env`, `src/`, `bin/`, `var/` are not reachable. Verify: `https://your-mirror/.env`, `/.env.php`, `/src/autoload.php`, `/composer.json` return no project content. `php bin/mirrorgate check --network` probes the first three.
- [ ] `STORAGE_DIR` is outside the document root and writable only by the PHP user.
- [ ] `bin/mirrorgate check` (or the admin `/check`) shows no `FAIL`, and every `WARN` is understood.
- [ ] `enable_post_data_reading = 0` for the web handler.
- [ ] `MIRROR_URL` is `https://`; `HSTS_MODE=own` if you want HSTS (without `preload` unless you are sure).
- [ ] `TRUSTED_PROXIES` and `TRUSTED_PROXY_HEADER` set if a CDN or proxy is in front.
- [ ] `AUTH_PASSTHROUGH_BLOCK` set if the origin has login areas; `FORWARD_COOKIES`/`FORWARD_AUTHORIZATION` disabled for anonymous sites.
- [ ] Admin disabled, or enabled with a random 64 hex character token, `ADMIN_IP_ALLOW`, and HTTPS.
- [ ] `ALLOW_PRIVATE_ORIGIN`, `ORIGIN_ALLOW_HTTP`, `ALLOW_SECRETS_IN_DOCROOT`, `DAEMON_ALLOW_PUBLIC_BIND` and `DAEMON_ALLOW_PRIVATE_TARGETS` are all off.
- [ ] Prefix mode and `EXTRA_ORIGINS` are not used (one instance per subdomain with `HOST_MAP` instead).
- [ ] Limits sized for your PHP worker pool (`RATE_LIMIT_MAX_CONCURRENT`, `GLOBAL_MAX_CONCURRENT`, `MAX_CONCURRENT_STREAMS`).
- [ ] Log level `info` only while you need the access log; logs and cache directories are on a disk with space.
- [ ] Daemon listeners (if any) bound to loopback, behind TLS, with `DAEMON_TARGET_ALLOWLIST` as small as possible.
- [ ] You have read the first section of this page.

## Reporting a vulnerability

Please report security problems privately through the repository's security policy (GitHub: `moonshadowrev/mirrorgate`, "Security" tab, "Report a vulnerability") instead of opening a public issue. Include the version (`php bin/mirrorgate version`), PHP and server software, a description and, if possible, a minimal request that reproduces the problem. Do not include real credentials or customer data.
