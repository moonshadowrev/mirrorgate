# Configuration reference

MirrorGate is configured entirely through environment variables, normally read from one file. Every variable is optional except `ORIGIN_URL` and `MIRROR_URL`. This page lists all of them with their defaults and accepted values. The same list, with short comments, is in `.env.example`.

Contents:

- [Where the configuration comes from](#where-the-configuration-comes-from)
- [File syntax and value types](#file-syntax-and-value-types)
- [Validation and error codes](#validation-and-error-codes)
- [Origin and mirror identity](#origin-and-mirror-identity)
- [Application](#application)
- [Upstream, limits and resilience](#upstream-limits-and-resilience)
- [Rewriting](#rewriting)
- [Cache](#cache)
- [Security, admin, rate limits and streaming](#security-admin-rate-limits-and-streaming)
- [Daemon](#daemon)
- [Example files](#example-files)

## Where the configuration comes from

The front controller (`public/index.php`) determines the project root (the directory above `public/`, unless the constant `MIRRORGATE_ROOT` is defined before the file is included) and then looks for the environment file in this order:

1. The constant `MIRRORGATE_ENV`, defined by a per-instance stub `index.php` (see [multi-instance.md](multi-instance.md)).
2. The process environment variable `MIRRORGATE_ENV`.
3. `<root>/.env.php`, when that file exists.
4. `<root>/.env`.

A relative `MIRRORGATE_ENV` value is resolved against the project root. The file must be readable by the PHP user and at most 256 KiB.

The command-line tools use the same lookup. `bin/mirrorgate` and `bin/mirrorgate-daemon` additionally accept `--env=/path/to/file`. `bin/mirrorgate-gc` has no such option; set the `MIRRORGATE_ENV` process variable when the file is not in the project root.

**Process environment overrides the file.** For every known variable name, a value in the process environment (as seen by PHP's `getenv()`) replaces the value from the file. This works for variables set in a PHP-FPM pool, a systemd unit or a container. An empty value is treated as "not set" and the default applies.

### The self-protecting `.env.php` form

When the document root cannot be moved and the project has to live inside it, use a file named `.env.php` whose very first line is exactly:

```
<?php http_response_code(404); exit; ?>
```

followed by the normal `KEY=VALUE` lines. If a web server ever executes the file it answers `404` and prints nothing, and the MirrorGate parser skips that first line. Boot is only allowed with this form inside the document root when `STORAGE_DIR` is outside it (see [Boot-time docroot check](#boot-time-docroot-check)).

### Boot-time docroot check

At boot, MirrorGate compares the real path of the environment file and of `STORAGE_DIR` with `$_SERVER['DOCUMENT_ROOT']`. If either lies inside the document root, it refuses to start and answers a generic `500`, unless:

- the environment file is a `.env.php` with the guard line above (this exempts the file only, `STORAGE_DIR` is still checked), or
- `ALLOW_SECRETS_IN_DOCROOT=true`.

The reason is logged as `ENV_FILE:in_docroot` or `STORAGE_DIR:in_docroot` in the bootstrap log (see [troubleshooting.md](troubleshooting.md#reading-the-logs)). On the command line there is no document root, so the check does not apply.

## File syntax and value types

- One `KEY=VALUE` per line. A leading `export ` is allowed. Keys must match `^[A-Z][A-Z0-9_]*$`.
- Lines starting with `#` and empty lines are ignored. In an unquoted value, a comment starts at whitespace followed by `#`.
- Double-quoted values support the escapes `\n`, `\r`, `\t`, `\"`, `\\` and `\$`. Single-quoted values are literal. A quoted value must be closed; text after the closing quote may only be a comment.
- There is no variable expansion.
- A UTF-8 byte order mark at the start of the file is ignored.

| Type | Accepted values |
|---|---|
| Boolean | `true`, `false`, `1`, `0`, `yes`, `no`, `on`, `off` (case-insensitive) |
| Integer | Decimal digits. Each variable has its own range, listed below |
| Duration | Integer seconds. Names ending in `_MS` are milliseconds |
| Size | Integer bytes, or a number with the suffix `K`, `M` or `G` (powers of 1024), for example `512K` or `10M` |
| List | Comma separated, whitespace around items is trimmed, empty items are dropped |
| Octal mode | Three or four octal digits such as `0640` |
| Host | A host name without scheme, port or path. Internationalised names are converted to Punycode |

Unknown variable names in the file are ignored at runtime and reported as warnings by `bin/mirrorgate check` (`env.unknown_key`). Lines that are not `KEY=VALUE` are reported as `env.syntax`.

## Validation and error codes

All variables are validated together at boot. If anything is wrong, the visitor sees a generic `500` page with a request ID and the details go to the bootstrap log (codes only, never values). On the command line, `bin/mirrorgate` prints the full list and exits with status 2.

Each problem has the form `VARIABLE:reason`. Common reasons:

| Reason | Meaning |
|---|---|
| `missing` | A required variable is not set |
| `not_boolean`, `not_integer`, `not_bytes`, `not_octal_mode` | The value cannot be parsed |
| `out_of_range` | The value is outside the allowed range |
| `invalid_choice` | Not one of the allowed keywords |
| `invalid_url`, `invalid_scheme`, `invalid_host`, `invalid_port`, `userinfo_not_allowed`, `path_not_allowed` | Problems in a URL value |
| `https_required` | `ORIGIN_URL` uses `http` without `ORIGIN_ALLOW_HTTP=true` |
| `loop` | `MIRROR_URL` is the origin host or a subdomain of it |
| `ack_required` | Prefix mode or `EXTRA_ORIGINS` without `PREFIX_MODE_ACK_SHARED_ORIGIN=true` |
| `placeholder`, `too_short`, `repeated_character` | Weak `ADMIN_TOKEN` |
| `below_dns_ttl`, `below_connect_timeout`, `below_base`, `below_cooldown`, `below_min_ttl` | A value is smaller than the value it depends on |
| `file_unreadable`, `not_found` | Problems with `TRUSTED_PROXIES` list files or `CA_BUNDLE` |
| `ENV_FILE:not_found`, `ENV_FILE:not_readable` | The environment file is missing or PHP cannot read it |

## Origin and mirror identity

| Variable | Default | Accepted | Description |
|---|---|---|---|
| `ORIGIN_URL` | required | `scheme://host[:port]` | The remote site to mirror. No path, query, fragment or credentials. `https` is required unless `ORIGIN_ALLOW_HTTP=true`. |
| `MIRROR_URL` | required | `scheme://host[:port]` | Public URL of this mirror, same format. The host must differ from the origin host and must not be a subdomain of it. All absolute URLs that MirrorGate generates (rewritten links, cookies, `X-Forwarded-*` toward the origin) are built from this value, never from the incoming `Host` header. |
| `MIRROR_ALIASES` | empty | list of hosts | Extra inbound host names accepted and treated as this mirror (for example `www.ormantek.com`). IP literals are not allowed. Requests whose `Host` is not `MIRROR_URL`'s host or an alias get `421`. |
| `ORIGIN_ALLOW_HTTP` | `false` | boolean | Allow a plain `http://` origin. |
| `ALLOW_PRIVATE_ORIGIN` | `false` | boolean | Disable the private, loopback and reserved address block (the SSRF guard) for the origin and `EXTRA_ORIGINS`. Link-local addresses (including the cloud metadata address `169.254.169.254`), the unspecified address and IPv6 forms that embed them stay blocked regardless. Only for tests or an origin on a private network. |
| `ORIGIN_PORTS_ALLOW` | `80,443` | list of ports 1 to 65535 | Ports MirrorGate may connect to. The port of `ORIGIN_URL` is always added. |
| `ORIGIN_RESOLVE_IP` | empty | IPv4 or IPv6 address | When set, the primary origin host is connected at this address and no DNS lookup is made for it. For other allowed hosts (`EXTRA_ORIGINS`) it is only a fallback when DNS returns nothing. The address still goes through the SSRF guard. Ignored when `OUTBOUND_PROXY` is set. |
| `OUTBOUND_PROXY` | empty | `http://`, `https://`, `socks5://`, `socks5h://` URL | Send all upstream traffic through this proxy. Prefer `socks5h://` so the proxy resolves host names. Credentials are allowed in the URL and are masked in `config:dump`, the admin `/check` output and logs. When set, the proxy is the SSRF boundary: no local DNS resolution or private-address check is made, only the host and port allow lists apply. `bin/mirrorgate check` warns about `socks5://`. |
| `CA_BUNDLE` | empty | path to a PEM file | CA bundle for hosts with an outdated system trust store. Relative paths are resolved against the directory of the environment file. The file must exist, otherwise boot fails with `CA_BUNDLE:not_found`. TLS verification itself can never be disabled. |
| `HOST_MAP` | empty | `originhost=mirrorhost,...` | Rewrite pairs for sibling instances. See [Host map rules](#host-map-rules) and [multi-instance.md](multi-instance.md). |
| `EXTRA_ORIGINS` | empty | list of hosts, at most 32 | Additional origin hosts served in prefix mode. Each must equal the origin host or be a subdomain of it. Rejected unless `PREFIX_MODE_ACK_SHARED_ORIGIN=true`. |
| `SUBDOMAIN_MODE` | `off` | `off`, `prefix` | `prefix` serves extra origin hosts below `SUBDOMAIN_PREFIX/<host>/` on the single mirror host. This merges those hosts into one browser origin (shared cookies, storage and script access). Requires `PREFIX_MODE_ACK_SHARED_ORIGIN=true`. `bin/mirrorgate check` reports it as `FAIL`. One instance per subdomain with `HOST_MAP` is the recommended alternative. |
| `SUBDOMAIN_PREFIX` | `/__mg/s` | path | URL prefix for prefix mode. Matches `^/[A-Za-z0-9_./-]{1,64}$`, no trailing slash, no `..`, no `//`. |
| `PREFIX_MODE_ACK_SHARED_ORIGIN` | `false` | boolean | Explicit acknowledgement that prefix mode and `EXTRA_ORIGINS` make all listed hosts share the mirror's cookies, storage and script access. |
| `DNS_TTL` | `60` | 1 to 3600 seconds | How long a validated DNS answer for the origin is cached under `STORAGE_DIR/dns`. |
| `DNS_STALE_TTL` | `max(3600, DNS_TTL)` | 1 to 86400 seconds, not below `DNS_TTL` | How long an expired answer may still be reused when the resolver fails. |
| `WS_PUBLIC_URL` | empty | `ws://host[:port]` or `wss://host[:port]` | Public base of the daemon's WebSocket listener. Scheme, host and optional port only; a path is rejected. When set, `ws(s)://` literals that point at the origin are rewritten to this base (the path of the literal is kept). When empty they are left untouched, because PHP cannot serve WebSockets. See [daemon.md](daemon.md#websocket-urls). |

### Host map rules

Each `HOST_MAP` entry is `originhost=mirrorhost`. The origin side must be one of:

- the primary origin host (then the mirror side must equal the host of `MIRROR_URL`),
- a subdomain of the primary origin host, or
- a parent domain of the primary origin host that still has at least two labels (for example `overlord.team` when the origin is `api.overlord.team`).

Entries naming a sibling subdomain of a subdomain origin (for example `cdn.overlord.team` while `ORIGIN_URL` is `https://api.overlord.team`) are rejected with `HOST_MAP:origin_side_not_related`. The same origin host may not appear twice. Both sides must be host names, not IP literals.

## Application

| Variable | Default | Accepted | Description |
|---|---|---|---|
| `APP_ENV` | `production` | `production`, `development` | Validated and shown by `config:dump`. It does not change behaviour in this version. |
| `APP_DEBUG` | `false` | boolean | Forces the log level to `debug`. It never changes what is sent to clients: stack traces and internals are never output. |
| `APP_INSTANCE_ID` | first 12 hex characters of `sha256("<origin host>\|<mirror host>")` | `^[a-z0-9_-]{4,32}$` | Namespaces the cache, locks and loop detection. Must differ per instance if instances share any storage directory. The default is already unique per origin/mirror host pair. |
| `APP_TIMEZONE` | `UTC` | PHP time zone identifier | Time zone used for log time stamps and date parsing. |
| `STORAGE_DIR` | `<directory of the env file>/var` | path | Runtime data: `cache/`, `logs/`, `run/`, `ratelimit/`, `locks/`, `breaker/`, `dns/`. Absolute, or relative to the directory of the env file. Must be writable by PHP and must not be inside the web root or inside `public/`. A deny-all `.htaccess` and an empty `index.html` are written into every directory MirrorGate creates below it. |
| `LOG_LEVEL` | `warning` | `debug`, `info`, `warning`, `error` | Minimum level written to the log. The access log (one JSON line per request) is written at `info`, so set `LOG_LEVEL=info` to get it. |
| `LOG_FILE` | `STORAGE_DIR/logs/mirrorgate-<12 hex>.log` | path | Log file. When unset, the file name gets an unguessable suffix so it cannot be fetched by guessing. An explicit value is used as written. |
| `LOG_MAX_BYTES` | `5M` | 64K to 1G | The log is rotated once (to `<file>.1`) when it exceeds this size. |
| `LOG_PATH_MODE` | `segments` | `segments`, `hash`, `full` | How request paths appear in the access log. `segments` keeps only the first path segment as `/first/*`, `hash` writes 12 hex characters of a keyed hash, `full` writes the whole path. Query strings, `Referer`, cookies and authorization values are never logged. With `full` the cache metadata also keeps plain paths; otherwise it stores keyed hashes only. |
| `ALLOW_SECRETS_IN_DOCROOT` | `false` | boolean | Skip the boot-time check that the env file and `STORAGE_DIR` are outside the document root. Do not enable it unless the web server provably denies access to those paths. |
| `STORAGE_FILE_MODE` | `0640` | octal | Mode for files MirrorGate creates. Use `0644` with `mod_php` on hosts where PHP runs as `nobody` or `www-data` for every account; keep `0640` or `0600` for PHP-FPM, suPHP or LiteSpeed with one user per account. |
| `STORAGE_DIR_MODE` | `0750` | octal | Mode for directories MirrorGate creates. Use `0755` for the `mod_php` case above. |
| `ERROR_PAGE_LANG` | `en` | `en`, `fa` | Language of the built-in error pages. `fa` is right-to-left Persian. |
| `ERROR_PAGE_DIR` | empty | directory | Custom error templates. See [Custom error pages](#custom-error-pages). |
| `ERROR_PAGE_UPSTREAM` | `gateway` | `gateway`, `all`, `off` | Which origin error responses are replaced by the friendly page: `gateway` (origin `502`, `503`, `504`), `all` (every origin `5xx`) or `off` (origin error pages pass through). |
| `EXPOSE_CACHE_HEADERS` | `true` | boolean | Add `X-MirrorGate-Cache`, `Age` and `Cache-Status` to proxied responses. |
| `ADD_VIA` | `true` | boolean | Add `Via: 1.1 mirrorgate` to requests sent to the origin and to responses sent to clients. |

### Custom error pages

Built-in templates are `templates/error.html` (English) and `templates/error.fa.html` (Persian). With `ERROR_PAGE_DIR` set, MirrorGate first looks for `<ERROR_PAGE_DIR>/<status>.html` (for example `502.html`) and falls back to the built-in template. A template can be at most 64 KiB. These placeholders are replaced, all values HTML-escaped:

| Placeholder | Value |
|---|---|
| `{{status}}` | HTTP status code |
| `{{title}}` | Short title in the configured language |
| `{{message}}` | Fixed explanatory sentence in the configured language |
| `{{request_id}}` | The request ID (16 hex characters), also sent as `X-MirrorGate-Request-Id` |
| `{{lang}}` | `en` or `fa` |
| `{{dir}}` | `ltr` or `rtl` |

Error pages never contain origin host names, IP addresses, curl messages or stack traces. They are sent with `Cache-Control: no-store` and a strict Content-Security-Policy, so inline styles are allowed but scripts and external resources are not.

## Upstream, limits and resilience

| Variable | Default | Accepted | Description |
|---|---|---|---|
| `ORIGIN_CONNECT_TIMEOUT` | `5` | 1 to 60 s | Time allowed to connect to the origin. |
| `ORIGIN_TIMEOUT` | `30` | 1 to 300 s, not below the connect timeout | Total time allowed per origin request. |
| `ORIGIN_LOW_SPEED_TIME` | `20` | `0` or 5 to 300 s | Abort a transfer slower than `ORIGIN_LOW_SPEED_LIMIT` for this long. `0` disables the check. |
| `ORIGIN_LOW_SPEED_LIMIT` | `256` | 1 or more bytes per second | Threshold for the low-speed check. |
| `ORIGIN_RETRIES` | `1` | 0 to 3 | Retries for `GET`, `HEAD` and `OPTIONS` only, and only before the response started. DNS, connect, connect-timeout, TLS and connection-reset failures are retried; a total timeout is not. |
| `ORIGIN_RETRY_BASE_MS` | `100` | 10 to 5000 | Base of the exponential back-off with jitter. |
| `ORIGIN_RETRY_CAP_MS` | `max(1000, base)` | not below the base, at most 10000 | Cap of the back-off. |
| `ORIGIN_HTTP_VERSION` | `1.1` | `1.1`, `2` | HTTP version toward the origin. `2` is used only if libcurl supports it, otherwise `1.1` is used silently. |
| `MAX_REQUEST_BODY_BYTES` | `10M` | 0 to 2G | Largest accepted request body. Also capped by PHP's `post_max_size` when that is non-zero. `0` rejects every non-empty body. Larger bodies get `413`. |
| `MAX_URL_LENGTH` | `8192` | 256 to 65536 | Longer request targets get `414`. |
| `MAX_HEADER_COUNT` | `100` | 10 to 500 | More request headers get `431`. |
| `MAX_HEADER_BYTES` | `8192` | 1K to 64K | Largest single request header line; longer lines get `431`. |
| `MAX_STREAM_BYTES` | `100M` | 0 or more | Bytes relayed in one passthrough stream (large downloads, SSE). The transfer is cut when reached. `0` means unlimited. |
| `ALLOWED_METHODS` | `GET,HEAD,POST,PUT,PATCH,DELETE,OPTIONS` | subset of those seven | Other methods get `405` with an `Allow` header. `TRACE` and `CONNECT` can never be allowed. Unknown methods get `501`. |
| `BREAKER_ENABLED` | `true` | boolean | Circuit breaker around origin failures. |
| `BREAKER_THRESHOLD` | `5` | 1 to 100 | Failures within the window that open the breaker. |
| `BREAKER_WINDOW` | `30` | 5 to 600 s | Failure counting window. |
| `BREAKER_COOLDOWN` | `30` | 5 to 3600 s | Initial time the breaker stays open before one probe request is let through. |
| `BREAKER_MAX_COOLDOWN` | `max(300, cooldown)` | not below the cooldown, at most 86400 | The open time doubles after each failed probe up to this cap. |
| `FORWARD_COOKIES` | `true` | boolean | Forward the visitor's `Cookie` header to the origin (cookies named `mg_*` and `__Host-mg_*` are always removed). Never sent on cache-fill requests. |
| `FORWARD_AUTHORIZATION` | `true` | boolean | Forward the `Authorization` header. Never sent on cache-fill requests. |
| `FORWARD_CLIENT_IP` | `true` | boolean | Send `X-Forwarded-For` with the resolved client address on requests that are not cache fills. |
| `REQUEST_HEADER_STRIP` | empty | list of lowercase header names, a trailing `*` makes a prefix | Extra client request headers to drop before the request reaches the origin. Added to the built-in list of spoofable client-address, scheme and identity headers. Header names containing `_` are always dropped. |

With `FORWARD_COOKIES` and `FORWARD_AUTHORIZATION` at their defaults the mirror operator can read every credential that passes through. See [security.md](security.md#the-mirror-operator-sees-everything).

## Rewriting

How these switches interact with content types is described in [rewriting.md](rewriting.md).

| Variable | Default | Accepted | Description |
|---|---|---|---|
| `REWRITE_ENABLED` | `true` | boolean | Master switch for response body rewriting. When `false` no body is modified. Response headers (`Location`, `Set-Cookie`, CORS, CSP, `Link`) are still rewritten, because they are needed for the mirror to work at all. |
| `REWRITE_HTML` | `true` | boolean | Rewrite HTML (attributes, `srcset`, meta refresh, selected `<meta>` tags, inline JSON data blocks). |
| `REWRITE_CSS` | `true` | boolean | Rewrite CSS `url()` and `@import`. |
| `REWRITE_JSON` | `true` | boolean | Rewrite JSON, `+json` types and web app manifests. |
| `REWRITE_XML` | `true` | boolean | Rewrite XML, RSS, Atom and sitemaps. |
| `REWRITE_JS` | `false` | boolean | Replace full origin host literals in JavaScript. Never tokenises. Needed for hard-coded API hosts in bundles. |
| `REWRITE_RSC` | `false` | boolean | Rewrite `text/x-component` (React Server Components). Unsafe because of length-prefixed rows. |
| `REWRITE_VISIBLE_TEXT` | `false` | boolean | Also replace the origin host inside HTML text nodes (the visible text of a page). By default only attributes and data blocks are rewritten, so a sentence such as "Welcome to overlord.team" stays as written. |
| `REWRITE_REQUEST_BODY` | `false` | boolean | Rewrite the mirror host back to the origin host in form-urlencoded and JSON request bodies up to 1 MiB. |
| `REWRITE_STRIP_INTEGRITY` | `true` | boolean | Remove Subresource Integrity hashes from tags that point at a rewritten same-origin resource. Third-party hashes are never touched. |
| `REWRITE_MAX_BYTES` | `8M` | 64K to 64M | Bodies larger than this are streamed unmodified. Also the limit for the decoded size of compressed bodies. |
| `REWRITE_SITEMAP_MAX_BYTES` | `16M` | 64K to 256M | Same limit for sitemaps and `robots.txt`. Rewriting needs several times this much memory; a body that does not fit into `memory_limit` is passed through unrewritten and not cached. |
| `SEO_MODE` | `mirror` | `mirror`, `canonical-origin`, `noindex` | How canonical links and indexing hints are treated. |
| `CSP_MODE` | `rewrite` | `rewrite`, `strip`, `passthrough` | Handling of `Content-Security-Policy` headers and CSP `<meta>` tags. |
| `SERVICE_WORKER_MODE` | `passthrough` | `passthrough`, `rewrite`, `block` | Handling of service worker scripts. |
| `HSTS_MODE` | `strip` | `strip`, `own` | `strip` removes the origin's HSTS header. `own` sends `HSTS_VALUE`. |
| `HSTS_VALUE` | `max-age=31536000` | up to 200 characters of `A-Za-z0-9=;,- _"` | Used when `HSTS_MODE=own`. `preload` is never added for you; if you add it `check` warns. |
| `STRIP_ALT_SVC` | `true` | boolean | Remove the `Alt-Svc` response header. |
| `COOKIE_DOMAIN_MODE` | `drop` | `drop`, `rewrite` | `drop` turns cookies into host-only cookies of the mirror host. `rewrite` maps an origin `Domain` attribute to the mirror host when the browser would accept it. |
| `COOKIE_FORCE_SECURE` | `true` | boolean | Add `Secure` to cookies when the mirror is `https`. |
| `REDIRECT_EXTERNAL` | `pass` | `pass`, `block` | `pass` forwards redirects to unrelated hosts unchanged. `block` answers `502` instead. |
| `RECOMPRESS` | `true` | boolean | gzip buffered text responses when the client accepts it and `ext-zlib` exists. |
| `RESPONSE_HEADER_STRIP` | empty | list of lowercase header names, a trailing `*` makes a prefix | Extra response headers to remove, applied together with the built-in deny list. |

## Cache

The cache is described in [caching.md](caching.md).

| Variable | Default | Accepted | Description |
|---|---|---|---|
| `CACHE_ENABLED` | `true` | boolean | Master switch for the response cache. |
| `CACHE_DIR` | `STORAGE_DIR/cache` | path | Cache directory, per instance. Entries live in `<CACHE_DIR>/<APP_INSTANCE_ID>/`. Must not be inside the web root. |
| `CACHE_MAX_BYTES` | `256M` | 1M or more | Total cache size. |
| `CACHE_MAX_OBJECT_BYTES` | `min(5M, CACHE_MAX_BYTES)` | 1 or more, at most `CACHE_MAX_BYTES` | Largest single cached object. |
| `CACHE_MAX_ENTRIES` | `20000` | 100 to 1000000 | Hard cap on cache entries, which protects hosting inode quotas. When reached, new entries are refused after one bounded garbage-collection pass. |
| `CACHE_DEFAULT_TTL` | `300` | 0 to 31536000 s | Freshness when the origin gives none and there is no `Last-Modified` heuristic. |
| `CACHE_MIN_TTL` | `0` | 0 to 31536000 s | Lower clamp for freshness. |
| `CACHE_MAX_TTL` | `max(86400, CACHE_MIN_TTL)` | not below `CACHE_MIN_TTL` | Upper clamp for freshness. |
| `CACHE_NEGATIVE_TTL` | `60` | 0 to 31536000 s | Freshness of `404` and `410` responses. |
| `CACHE_SWR_SECONDS` | `60` | 0 to 31536000 s | Stale-while-revalidate window when the origin sends none. |
| `CACHE_STALE_IF_ERROR_SECONDS` | `604800` | 0 to 31536000 s | How long a stale entry may be served when the origin fails (default 7 days), unless the origin sets its own `stale-if-error`. |
| `CACHE_STATIC_EXTENSIONS` | `js,css,mjs,png,jpg,jpeg,gif,webp,avif,svg,ico,woff,woff2,ttf,otf,eot,mp4,webm,mp3` | list of lowercase extensions | Extensions that stay cacheable for cookie-bearing requests in `static-only` mode. `json`, `xml`, `txt`, `map` and `webmanifest` are deliberately not included because they are often personalised. |
| `CACHE_RULES` | empty | `glob=ttl,...` | Per-path TTL overrides, first match wins. See [caching.md](caching.md#cache-rules). |
| `CACHE_COOKIE_MODE` | `static-only` | `bypass`, `static-only` | `bypass`: any `Cookie` header bypasses the cache. `static-only`: cookie-bearing requests are cached only for `CACHE_STATIC_EXTENSIONS`. |
| `CACHE_HONOR_CLIENT_NOCACHE` | `false` | boolean | Honour a client's `Cache-Control: no-cache`, `no-store`, `max-age=0` or `Pragma: no-cache` by bypassing the cache. Off by default so anonymous clients cannot bypass or evict the cache. |
| `CACHE_IGNORE_MUST_REVALIDATE_ON_ERROR` | `true` | boolean | Serve stale content on origin errors even for `must-revalidate` or `no-cache` responses (a deliberate deviation from RFC 9111 section 4.2.4). |
| `CACHE_COALESCE_WAIT_MS` | `1500` | 0 to 30000 | How long a request waits for a concurrent fill of the same object before it fetches anyway. Waiters occupy a PHP worker, so keep it small on shared pools. |
| `CACHE_GC_PROBABILITY` | `1` | 0 to 100 | Percent chance per request that garbage collection is considered after the response. `0` disables request-triggered GC (use cron). |
| `CACHE_GC_INTERVAL` | `300` | 1 to 86400 s | Minimum time between request-triggered GC runs. |
| `CACHE_LOCK_MODE` | `flock` | `flock`, `mkdir` | Lock implementation. Use `mkdir` on NFS. |
| `CACHE_IGNORE_QUERY_PARAMS` | empty | list of parameter names | Parameters removed from the cache key and from the query sent to the origin on cache fills, for example `utm_source,utm_medium,fbclid`. Names are matched case-insensitively. |
| `CACHE_VARY_EXTRA` | empty | list of header names | Request headers always included in the cache key. Listing a header here is the only way `Accept`, `Accept-Language` or `User-Agent` reach the origin on cache fills. An origin response whose `Vary` names a header that cache fills never send is not stored unless the header is listed here. |
| `CACHE_BYPASS_HEADERS` | `x-api-key,x-auth-token,x-access-token,proxy-authorization,x-csrf-token,x-xsrf-token` | list of header names | Request headers that make a request bypass the cache (they carry credentials the anonymous cache fill would drop). Setting this variable replaces the default list. Requests with a `Service-Worker` header or a cross-site `Origin` header bypass the cache as well. |
| `CACHE_MAX_QUERY_VARIANTS` | `20` | 1 to 1000 | Distinct query strings stored per path. Beyond this the oldest variant of that path is evicted, so cache-busting URLs cannot push out other pages. |
| `CACHE_STATUSES` | `200,301,308,404,410` | subset of `200,203,204,300,301,308,404,405,410,414,501` | Status codes that may be cached. |

## Security, admin, rate limits and streaming

| Variable | Default | Accepted | Description |
|---|---|---|---|
| `TRUSTED_PROXIES` | empty | list of IPs or CIDRs, plus `@file:<path>` | Reverse proxies or CDNs whose forwarded client IP header is trusted. Empty means forwarded headers are never trusted and the TCP peer address is used. See [Trusted proxies](#trusted-proxies). |
| `TRUSTED_PROXY_HEADER` | `x-forwarded-for` | `x-forwarded-for`, `cf-connecting-ip`, `x-real-ip` | Header that carries the client address when the peer is a trusted proxy. |
| `BLOCKED_PATHS` | empty | list of path globs | Paths answered with a local `404` and never sent to the origin. See [Path globs](#path-globs). |
| `AUTH_PASSTHROUGH_BLOCK` | empty | list of path globs | Same effect as `BLOCKED_PATHS`, intended for login, OAuth and session endpoints. Recommended whenever the origin has authenticated areas. Example: `/login*,/oauth/*,/api/auth/*,/signin*,/account/*`. |
| `SECURITY_HEADERS` | `true` | boolean | Add `Referrer-Policy`, `X-Frame-Options` and a strict `Content-Security-Policy` to admin responses. `X-Content-Type-Options: nosniff` and `Cache-Control: no-store` are always sent on responses MirrorGate generates, and the friendly error pages always carry the full set. Proxied responses are not touched by this switch. |
| `ADMIN_ENABLED` | `false` | boolean | Enable the admin endpoints. When off, the admin prefix is an ordinary path and is proxied like any other. |
| `ADMIN_TOKEN` | empty | 32 or more characters | Bearer secret, required when the admin is enabled. Whitespace and control characters are not allowed; values containing `change-me`, `changeme`, `example` or `token`, and values made of one repeated character, are rejected. Generate one with `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`. |
| `ADMIN_PATH_PREFIX` | `/__mirrorgate` | `^/[A-Za-z0-9_-]{3,32}$` | URL prefix of the admin endpoints. While the admin is enabled this prefix is reserved: it is never proxied or cached. |
| `ADMIN_IP_ALLOW` | empty | list of IPs or CIDRs | Clients allowed to reach the admin. Empty means any address. Compared with the resolved client address, so set `TRUSTED_PROXIES` behind a CDN. |
| `ADMIN_FAIL_LIMIT` | `5` | 1 to 1000 | Failed admin authentications counted per client (IPv6 per /64) within the window. The counter is kept for abuse visibility; it never changes the answer, which is always the generic `404`, and never locks out a valid token. |
| `ADMIN_FAIL_WINDOW` | `900` | 1 to 86400 s | Window of the failure counter. |
| `ADMIN_GLOBAL_FAIL_LIMIT` | `50` | 1 to 100000 | Accepted for compatibility. It has no effect in this version. |
| `ADMIN_REQUESTS_PER_MINUTE` | `60` | 1 to 10000 | Accepted for compatibility. It has no effect in this version. |
| `ADMIN_ALLOW_HTTP` | `false` | boolean | Allow the admin while `MIRROR_URL` is `http://`. The token travels in a header, so this is discouraged. Without it the admin answers `404` to any request that did not arrive over TLS: directly, or announced by `X-Forwarded-Proto: https` from a peer inside `TRUSTED_PROXIES`. |
| `RATE_LIMIT_ENABLED` | `true` | boolean | Per-client request rate limiting. |
| `RATE_LIMIT_REQUESTS` | `120` | 1 to 100000 | Requests per client per window. |
| `RATE_LIMIT_WINDOW` | `60` | 1 to 3600 s | Length of the (fixed) window. |
| `RATE_LIMIT_DRIVER` | `auto` | `auto`, `apcu`, `file` | `auto` uses APCu when it is usable and falls back to files under `STORAGE_DIR/ratelimit`. `apcu` without a working APCu is reported as `FAIL` by `check`. |
| `RATE_LIMIT_MAX_CONCURRENT` | `4` | 0 to 1000 | In-flight requests per client (IPv6 per /64). `0` turns it off. The default assumes a PHP worker pool of 5 to 20 workers shared by everything on the account. |
| `RATE_LIMIT_ORIGIN_FETCHES_PER_MINUTE` | `600` | 0 to 1000000 | Cap on origin fetches by this instance per minute. `0` turns it off. Beyond it, requests that need the origin get `503` with `Retry-After`. |
| `RATE_LIMIT_EXEMPT_IPS` | empty | list of IPs or CIDRs | Clients that skip rate and concurrency limits. |
| `RATE_LIMIT_SALT` | empty | at least 16 characters, no control characters | Secret salt for the keyed hashes used for limiter keys, log hashes and cache path metadata. Leave empty: 32 random bytes are generated once and stored at `STORAGE_DIR/run/salt` (mode 0600). Set it explicitly if `STORAGE_DIR` is not writable. |
| `GLOBAL_MAX_CONCURRENT` | `8` | 0 to 1000 | Cap on requests doing origin I/O at the same time. Excess requests get `503` with `Retry-After: 1`. Cache hits do not consume a slot. `0` turns it off. |
| `CONCURRENCY_SHARED_DIR` | empty | absolute path | Directory outside the web root, writable by PHP. Instances that point at the same directory share one slot pool for `GLOBAL_MAX_CONCURRENT`, which makes the cap account-wide. Use the same `GLOBAL_MAX_CONCURRENT` value in every instance that shares it. Empty means the cap is per instance. |
| `SSE_ENABLED` | `true` | boolean | Stream `text/event-stream` responses incrementally, with heartbeats and the duration and slot limits below. Setting `false` disables that special handling; the event stream is then no longer delivered incrementally, so leave it on unless you know the origin never sends SSE. |
| `SSE_MAX_DURATION` | `60` | 10 to 3600 s | Maximum time one SSE response stays open; `EventSource` reconnects by itself. Further clamped by the PHP runtime limits, see [Streaming limits](#streaming-limits). |
| `SSE_HEARTBEAT` | `20` | `0` or 5 to 120 s | Interval of `: ping` comment lines, sent only at event boundaries. `0` disables heartbeats. |
| `MAX_CONCURRENT_STREAMS` | `3` | 1 to 100 | Concurrent SSE responses for this instance. Over the limit the visitor gets `503` with `Retry-After: 5`. |

### Trusted proxies

`TRUSTED_PROXIES` is a comma separated list. Each item is one of:

- an IPv4 or IPv6 address, or a CIDR range,
- `@file:<path>`: a file with one CIDR per line (`#` comments allowed), relative to the directory of the env file. Use it for the ranges your CDN publishes and refresh the file periodically, since provider ranges change.

The forwarded header is only used when the TCP peer is inside the list. For `x-forwarded-for` the chain is walked from the right, trusted hops are skipped and the first untrusted valid address wins; any invalid entry stops the walk and the peer address is used.

If the site sits behind a CDN or the host's reverse proxy and `TRUSTED_PROXIES` is empty, every visitor shares one address and therefore one rate-limit bucket. `bin/mirrorgate check` detects this (`proxy.hint`) from sampled, hashed request data.

### Path globs

`BLOCKED_PATHS` and `AUTH_PASSTHROUGH_BLOCK` take globs where `*` matches any characters (including `/`) and `?` matches one character. A pattern must start with `/` or `*`. Matching is anchored to the whole path, case-insensitive, and ignores the query string. The path is also tested after decoding `%2F` and `%5C` and after removing `;param` suffixes, so encoded variants do not slip through. In prefix mode the pattern is applied to the origin path as well.

### Streaming limits

PHP workers and process time limits decide how long a stream can live. If `set_time_limit()` is callable and the SAPI is not LiteSpeed, streaming paths are not time-limited by PHP. Otherwise a request may run for about `max_execution_time - 5` seconds (25 seconds when `max_execution_time` is 0, and never less than 5), and at most 25 seconds under the LiteSpeed SAPI, whatever `set_time_limit()` allows. `SSE_MAX_DURATION` and passthrough transfers are clamped to that ceiling, and `bin/mirrorgate check` reports it as `ini.runtime_ceiling`. SSE therefore works on a best-effort basis on shared hosting. The admin endpoint `GET /__mirrorgate/streamtest` shows whether a layer in front of PHP buffers output ([troubleshooting.md](troubleshooting.md#streaming-and-sse-are-buffered)).

## Daemon

These variables are read by `bin/mirrorgate-daemon`. The web application ignores them. The daemon also needs `ORIGIN_URL` and `MIRROR_URL` because it shares the configuration loader. Behaviour is described in [daemon.md](daemon.md).

| Variable | Default | Accepted | Description |
|---|---|---|---|
| `DAEMON_LISTEN_1` to `DAEMON_LISTEN_32` | empty | listener definition | One listener per variable. Numbers need not be contiguous. See [daemon.md](daemon.md#listener-syntax). |
| `DAEMON_ALLOW_PUBLIC_BIND` | `false` | boolean | Required for any bind address other than `127.0.0.0/8` or `::1`. |
| `DAEMON_MAX_CONNECTIONS` | `800` | 1 to 1000 | Simultaneous connections for the whole daemon. The `select()` descriptor limit further caps this to about 494 forwarded connections (two descriptors each). |
| `DAEMON_MAX_CONN_PER_IP` | `50` | 1 to 1000 | Simultaneous connections per client address (IPv6 per /64). |
| `DAEMON_IDLE_TIMEOUT` | `120` | 1 to 86400 s | Default idle timeout of a connection. Per listener override: `idle`. |
| `DAEMON_HANDSHAKE_TIMEOUT` | `10` | 1 to 120 s | Time a WebSocket client has to send complete request headers. |
| `DAEMON_MAX_HEADER_BYTES` | `16K` | 1K to 1M | Size cap of the WebSocket handshake. |
| `DAEMON_BUFFER_HIGH_WATER` | derived, at most `256K` | 4K to 64M | Per-direction write buffer watermark (backpressure). When unset it is derived from `DAEMON_MEMORY_LIMIT` and `DAEMON_MAX_CONNECTIONS` so that all sessions together stay inside about 60 percent of the memory limit. |
| `DAEMON_UDP_SESSION_TTL` | `60` | 1 to 3600 s | Idle expiry of a UDP session. |
| `DAEMON_UDP_MAX_SESSIONS` | `1024` | 1 to 100000 | Maximum UDP sessions (a UDP listener is also limited to 1000 by the connection pool). |
| `DAEMON_UDP_MAX_DATAGRAM` | `65507` | 512 to 65507 bytes | Largest forwarded datagram. |
| `DAEMON_UDP_RATE_PER_SEC` | `200` | 1 to 100000 | Datagrams per client address per second. |
| `DAEMON_WS_MAX_LIFETIME` | `0` | 0 to 2592000 s | Maximum WebSocket connection lifetime. `0` is unlimited. |
| `DAEMON_WS_ORIGIN_ALLOW` | origin of `MIRROR_URL` plus the origin of every `MIRROR_ALIASES` host | list of `scheme://host[:port]` | Browser `Origin` values accepted on WebSocket handshakes. |
| `DAEMON_WS_ALLOW_NO_ORIGIN` | `false` | boolean | Accept WebSocket handshakes without an `Origin` header (non-browser clients). Per listener override: `allow_no_origin`. |
| `DAEMON_UDP_CLIENT_ALLOW` | empty | list of IPs or CIDRs | Required (non-empty) for any UDP listener on a non-loopback address. Ranges wider than `/8` (IPv4) or `/32` (IPv6), including `0.0.0.0/0` and `::/0`, are rejected. |
| `DAEMON_UDP_MAX_AMPLIFICATION` | `2` | 1 to 1000 | Per client address, reply bytes may not exceed this ratio times the request bytes plus a one-time 4 KiB allowance. |
| `DAEMON_TCP_CLIENT_ALLOW` | empty | list of IPs or CIDRs | Clients allowed to use `tcp` and `grpc` listeners. Empty means any address. |
| `DAEMON_TARGET_ALLOWLIST` | origin host plus `EXTRA_ORIGINS` | list of hosts or IPs | Hosts the daemon may connect to. IP literals only if listed. A listener whose target is not in this list is refused at parse time. |
| `DAEMON_ALLOW_PRIVATE_TARGETS` | value of `ALLOW_PRIVATE_ORIGIN` | boolean | Allow private and loopback targets. Link-local and metadata addresses stay blocked. |
| `DAEMON_TARGET_DNS_TTL` | `60` | 1 to 3600 s | Cache time of target DNS answers. |
| `DAEMON_PID_FILE` | `STORAGE_DIR/run/daemon.pid` | path | PID file; also the single-instance lock. |
| `DAEMON_LOG_FILE` | `STORAGE_DIR/logs/daemon.log` | path | Daemon log file. |
| `DAEMON_STOP_FILE` | `STORAGE_DIR/run/daemon.stop` | path | Creating this file stops the daemon (polled four times a second). |
| `DAEMON_MEMORY_LIMIT` | `256M` | PHP memory size such as `256M`, or `-1` | `memory_limit` of the daemon process. The daemon restarts itself (exit code 3) when it uses more than 90 percent of it. |

## Example files

### Minimal

```ini
ORIGIN_URL=https://overlord.team
MIRROR_URL=https://ormantek.com
```

### A typical public mirror

```ini
ORIGIN_URL=https://overlord.team
MIRROR_URL=https://ormantek.com
MIRROR_ALIASES=www.ormantek.com

LOG_LEVEL=info
ERROR_PAGE_LANG=fa

# The origin has a login area: do not carry it through the mirror.
AUTH_PASSTHROUGH_BLOCK=/login*,/oauth/*,/api/auth/*,/signin*,/account/*

# Cache tuning
CACHE_MAX_BYTES=128M
CACHE_MAX_ENTRIES=8000
CACHE_IGNORE_QUERY_PARAMS=utm_source,utm_medium,utm_campaign,fbclid,gclid
CACHE_RULES=/assets/*=86400,/api/*=-1

# The host sits behind Cloudflare
TRUSTED_PROXIES=@file:cloudflare-ips.txt
TRUSTED_PROXY_HEADER=cf-connecting-ip

# Shared hosting: keep the secrets out of the document root
STORAGE_DIR=/home/user/mirrorgate-data/overload
```

### Self-protecting file inside the document root

```
<?php http_response_code(404); exit; ?>
ORIGIN_URL=https://overlord.team
MIRROR_URL=https://ormantek.com
STORAGE_DIR=/home/user/mirrorgate-data/overload
```

Save this as `.env.php` and keep the guard line first.
