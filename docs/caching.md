# Caching

MirrorGate keeps a shared, on-disk HTTP cache so most visitors are served without contacting the origin, and so the mirror keeps working when the origin is down. This page explains what is cached, how long, how entries are keyed, how stale content is used, and how to purge and tune the cache. All settings are in [configuration.md](configuration.md#cache).

## Overview

- **Shared cache.** One stored copy per object serves every visitor. Therefore everything stored must be identical for all visitors; the rules below are strict about that.
- **Stores the rewritten response.** The body saved in the cache already has the origin host replaced by the mirror host (see [rewriting.md](rewriting.md)).
- **Plain files.** No database or extension is needed. Files live under `CACHE_DIR/<APP_INSTANCE_ID>/` (by default `STORAGE_DIR/cache/<id>/`). Writes are atomic, readers validate every file, and a damaged file is simply a cache miss.
- **Respects the origin.** Freshness comes from the origin's `Cache-Control`, `Expires` and `Last-Modified`, clamped by your settings.
- **Resilient.** Stale entries are served when the origin or the mirror's own capacity limits fail, for up to seven days by default.

## Which requests use the cache

A request is eligible only if all of these hold:

- the method is `GET` or `HEAD`,
- none of these request headers is present: `Authorization`, `Range`, `Upgrade`, `Service-Worker`, `RSC`, `Next-Router-State-Tree`, `Next-Router-Prefetch`, `Next-Url`, `Next-Action`,
- none of the headers in `CACHE_BYPASS_HEADERS` carries a value (default: `x-api-key`, `x-auth-token`, `x-access-token`, `proxy-authorization`, `x-csrf-token`, `x-xsrf-token`),
- an `Origin` header, if present, names the mirror host itself (cross-site requests bypass the cache),
- cookies are acceptable under `CACHE_COOKIE_MODE` (below),
- no `CACHE_RULES` entry for the path has TTL `-1`,
- the client did not send `Cache-Control: no-cache`, `no-store`, `max-age=0` or `Pragma: no-cache`, **if** `CACHE_HONOR_CLIENT_NOCACHE=true`. By default anonymous clients cannot bypass or evict cached content this way.

`POST`, `PUT`, `PATCH`, `DELETE` and `OPTIONS` are never cached. Server-Sent Events are never cached.

**Cookies.** `CACHE_COOKIE_MODE` decides what happens to requests that carry a `Cookie` header:

| Mode | Behaviour |
|---|---|
| `static-only` (default) | Cookie-bearing requests use the cache only when the path ends in one of `CACHE_STATIC_EXTENSIONS` (images, fonts, JS, CSS, media). Everything else, including HTML and JSON, goes to the origin |
| `bypass` | Any `Cookie` header bypasses the cache |

Extensions such as `json`, `xml`, `txt`, `map` and `webmanifest` are not in the default list because they are often personalised.

### Cache fills are anonymous

When a request is eligible, the request that MirrorGate sends to the origin to fill the cache is deliberately anonymous, so nothing about one visitor can end up in the shared copy:

- `Cookie` and `Authorization` are never sent, whatever `FORWARD_COOKIES` and `FORWARD_AUTHORIZATION` say.
- No client address is sent (`X-Forwarded-For` is only added to requests that are not cache fills).
- Of the client's headers only `Accept`, `Accept-Language` and `User-Agent` can be forwarded, and only when the header is listed in `CACHE_VARY_EXTRA`. Otherwise constants are used: `Accept: */*`, no `Accept-Language`, and `User-Agent: Mozilla/5.0 (compatible; MirrorGate/<version>)`.
- `Cache-Control` and `Pragma` from the client are dropped, so a client cannot force an origin hit.
- Client conditional headers (`If-None-Match`, `If-Modified-Since`) are not forwarded; MirrorGate evaluates them itself against the stored copy.

If an origin personalises by `Accept-Language` or `User-Agent`, add the header to `CACHE_VARY_EXTRA` so it is part of the cache key and reaches the origin. Without it, a response whose `Vary` names such a header is not stored.

Because a cache fill is anonymous, a logged-in visitor who requests HTML receives either a response fetched with their cookie (not stored, in `static-only` mode) or, for static files, an anonymous copy. Static assets are normally identical for everyone, which is why they are allowed.

## What is stored

A response is stored only when every condition holds:

- the request method was `GET` (a `HEAD` request is never stored and never refreshes an entry),
- the status is in `CACHE_STATUSES` (default `200,301,308,404,410`) and is not `206`,
- the origin did not send `Set-Cookie`,
- `Cache-Control` has neither `no-store` nor `private`,
- `Vary` is not `*`, does not name `Cookie` or `Authorization`, and only names headers that are either tolerated because cache fills never vary them (`Accept-Encoding`, `Origin`, `Content-Type`, `Service-Worker` and the Next.js/RSC request headers) or listed in `CACHE_VARY_EXTRA` (those become part of the key),
- the response has no `Content-Security-Policy` or `-Report-Only` header, and no `<meta http-equiv="Content-Security-Policy">` tag in HTML, that contains a `nonce-` source (a nonce is per response and would be stale in a shared copy),
- the body is not larger than `CACHE_MAX_OBJECT_BYTES`,
- the transfer was complete,
- `no-cache` responses are stored only if they carry a validator (`ETag` or `Last-Modified`) or a `CACHE_RULES` entry matches (they are then always revalidated),
- a rewrite of the body, if one was attempted, did not fail.

Further rules:

- **Negative caching.** `404` and `410` are kept for `CACHE_NEGATIVE_TTL` (default 60 seconds), or less if the origin sends a smaller lifetime. They are not stored for URLs with a query string unless a `CACHE_RULES` entry matches, so random query strings cannot fill the cache with error entries.
- **Origin `5xx` responses are never stored.**
- **Redirects.** `301` and `308` are cached by default; `302` and `307` are not. Add them to `CACHE_STATUSES` if you want them cached (allowed values: `200,203,204,300,301,308,404,405,410,414,501`).
- **Large files.** A response larger than `CACHE_MAX_OBJECT_BYTES` is not buffered; it is streamed through (reported as `X-MirrorGate-Cache: BYPASS`). For known large media extensions such as `mp4` or `zip` with no declared length, MirrorGate streams instead of buffering speculatively.

## Freshness

For a stored response MirrorGate computes three windows, all measured from the time of storing:

1. **Fresh time** (TTL). Taken from `s-maxage`, else `max-age`, else `Expires` (relative to the origin's `Date`). An `Age` header from an upstream cache is subtracted. If the origin gives none, and it sent `Last-Modified`, a heuristic of 10 percent of the time since the last modification is used; otherwise `CACHE_DEFAULT_TTL` (default 300 seconds). The result is clamped between `CACHE_MIN_TTL` and `CACHE_MAX_TTL`. A `no-cache` response gets a TTL of zero (it is stored only with a validator and is revalidated on every use); a `must-revalidate` or `proxy-revalidate` response keeps its TTL but gets no stale-while-revalidate window. A matching `CACHE_RULES` entry replaces all of this, including the clamps.
2. **Stale-while-revalidate window.** Directly after the fresh time: the origin's `stale-while-revalidate`, else `CACHE_SWR_SECONDS` (default 60). Zero for `must-revalidate` responses.
3. **Stale-if-error window.** Directly after the fresh time: the origin's `stale-if-error`, else `CACHE_STALE_IF_ERROR_SECONDS` (default 7 days). Both windows are capped at 30 days.

An entry is therefore in one of these states:

| State | When | What the visitor gets |
|---|---|---|
| Fresh | Within the TTL | Served from disk, `X-MirrorGate-Cache: HIT` |
| Stale-while-revalidate | After the TTL, within the SWR window | Served at once as `STALE`; the entry is refreshed after the response was sent (see below) |
| Stale-usable-on-error | After the SWR window, within the stale-if-error window | The origin is asked first; the stale copy is only used if the origin fails |
| Expired | Beyond all windows | Treated as a miss |

`must-revalidate`, `proxy-revalidate` and `no-cache` responses are not served stale in the SWR window. They may still be served in the stale-if-error window when `CACHE_IGNORE_MUST_REVALIDATE_ON_ERROR=true` (the default, a documented deviation from RFC 9111 section 4.2.4); set it to `false` to be strict.

## Revalidation and background refresh

- When an entry is stale and has a validator (`ETag` or `Last-Modified`), MirrorGate asks the origin conditionally (`If-None-Match` / `If-Modified-Since`). A `304` renews the entry without transferring the body and is reported as `X-MirrorGate-Cache: REVALIDATED`.
- For the stale-while-revalidate window the visitor is served immediately and the refresh runs after the response was sent. This needs the PHP SAPI to be able to detach the client connection (`fastcgi_finish_request()` in PHP-FPM, `litespeed_finish_request()` in LiteSpeed). Under `mod_php`, plain CGI or the PHP built-in server this is not possible, and such entries are treated as expired: the visitor waits for a synchronous revalidation, with a fallback to the stale copy if the origin fails. `bin/mirrorgate check` reports this as `sapi.detach`. At most one background refresh runs per request.
- **Request coalescing.** When many visitors request the same missing object at once, one of them fetches it while the others wait up to `CACHE_COALESCE_WAIT_MS` (default 1500 ms) for the entry to appear and then serve it. Visitors who do not get the lock but find a stale copy are served the stale copy instead of waiting. After the wait time runs out the request fetches from the origin itself (fail open). Waiters occupy a PHP worker, so keep the wait small on shared hosting. Locks are a fixed set of 256 files per instance (`flock`, or `mkdir` with `CACHE_LOCK_MODE=mkdir` on NFS).

## Stale-if-error

When the origin cannot deliver, MirrorGate serves the stale copy instead of an error page. This applies to:

- transport failures: DNS, connect, TLS, timeouts, connection resets, truncated transfers,
- origin responses `500`, `502`, `503` and `504`,
- failures that MirrorGate itself reports with a `5xx`: an open circuit breaker, the account-wide concurrency cap (`GLOBAL_MAX_CONCURRENT`) and the origin fetch budget (`RATE_LIMIT_ORIGIN_FETCHES_PER_MINUTE`).

It never applies to `4xx` responses from the origin: a real `404` or `403` is passed on. Responses served this way carry `X-MirrorGate-Cache: STALE`, and the cache lifetime sent to the browser is shortened to at most 30 seconds so browsers re-check soon. The detailed error handling is described in [architecture.md](architecture.md#failure-handling).

With the circuit breaker open, requests that have a stale entry are answered from it without any network call; requests without one receive a friendly `503` page with `Retry-After`.

## Response headers

When `EXPOSE_CACHE_HEADERS=true` (default) proxied responses carry:

| Header | Values |
|---|---|
| `X-MirrorGate-Cache` | `HIT`, `MISS` (fetched and stored), `EXPIRED` (an old entry was replaced), `STALE`, `REVALIDATED`, `BYPASS` (not cacheable or streamed; also every `HEAD` miss), `STREAM` (SSE), `ERROR` (a MirrorGate error page) |
| `Age` | Seconds since the entry was stored, for `HIT`, `STALE`, `REVALIDATED`, `MISS` and `EXPIRED` |
| `Cache-Status` | RFC 9211: `MirrorGate; hit` or `MirrorGate; hit; ttl=<n>` for a hit, `MirrorGate; fwd=miss`, `MirrorGate; fwd=stale; fwd-status=<n>` when serving stale after an origin error, `MirrorGate; fwd=bypass` |

Independent of that switch, `Via: 1.1 mirrorgate` is added when `ADD_VIA=true`.

Other behaviour visible to clients:

- Buffered and cached responses get a weak `ETag` of the form `W/"mg-<16 hex>"`, computed from the rewritten body. The origin's `ETag` and digest headers are removed because the body changed. `If-None-Match` and `If-Modified-Since` from the visitor are answered with `304` directly from the cache.
- `Accept-Ranges: none` is set on buffered responses. `Range` requests are not cached; they are forwarded to the origin in passthrough mode.
- The origin's `Cache-Control`, `Expires`, `Last-Modified` and `Vary` are passed to the browser unchanged (except the shortened lifetime on stale responses). Browsers therefore cache according to the origin's rules, independently of the mirror's cache.
- The stored body is kept gzip-compressed when `ext-zlib` exists, the body is larger than 1 KiB and the type is compressible; it is delivered compressed or decompressed according to the visitor's `Accept-Encoding`.

## Cache keys and fingerprints

The key of an object consists of:

- the instance ID (`APP_INSTANCE_ID`),
- a purge generation counter,
- the method class (cache entries are for `GET`; `HEAD` uses them too),
- the normalised path and query string, minus the parameters in `CACHE_IGNORE_QUERY_PARAMS`,
- a **rewrite fingerprint**: a hash of `ORIGIN_URL`, `MIRROR_URL`, `MIRROR_ALIASES`, `HOST_MAP`, the prefix-mode settings, `WS_PUBLIC_URL`, every `REWRITE_*` and related rewrite setting, and the internal rewriter version,
- the values of the request headers that the response varies on, which are the headers in `CACHE_VARY_EXTRA`.

Consequences:

- Changing rewrite-related settings, the origin or mirror URL, the host map or upgrading to a release with a new rewriter version means old entries are no longer found. You do not need to purge after such a change; the old files are removed by garbage collection.
- The `Host` header and `X-Forwarded-*` headers are not part of the key and cannot influence it.
- `CACHE_IGNORE_QUERY_PARAMS` removes tracking parameters from the key **and** from the query string that is sent to the origin on a cache fill. A stored response is therefore always a pure function of its key.
- Query variants are limited per path (`CACHE_MAX_QUERY_VARIANTS`, default 20); beyond that the oldest variant of that path is evicted first, so cache-busting URLs cannot push out other pages.
- No request data ever becomes part of a file name. File names are SHA-256 hex digests in sharded directories, every open is checked for containment in the cache directory and for symlinks, and paths are stored as keyed hashes unless `LOG_PATH_MODE=full`.

On disk, below `CACHE_DIR/<APP_INSTANCE_ID>/`:

| Path | Content |
|---|---|
| `e/<hh>/<hh>/<hash>` | One entry file per stored object: format marker `MGC1`, JSON metadata (status, headers, timestamps, CRC32), then the body |
| `idx/<hh>/<hash>.json` | Index per path: Vary selector and the variants that exist |
| `locks/<hh>.lock` | 256 shared lock files |
| `generation`, `soft-generation`, `usage.json`, `gc.*` | Purge counters, usage counters, garbage collection state |

Cache files are never executed or unserialised. They have no `.php` extension, and a deny-all `.htaccess` and an empty `index.html` are written into every directory under `STORAGE_DIR`.

## Cache rules

`CACHE_RULES` is a comma separated list of `glob=ttl` pairs, first match wins. The glob uses `*` (any characters, including `/`) and `?` (one character), is anchored to the whole path, is case-sensitive and ignores the query string. It must start with `/` or `*`. The TTL is in seconds; `-1` means "never cache this path".

```ini
# Long-lived assets, never cache the API, one hour for the blog
CACHE_RULES=/assets/*=86400,/_next/static/*=31536000,/api/*=-1,/blog/*=3600
```

Notes:

- A rule's TTL replaces the freshness computed from the origin, including the `CACHE_MIN_TTL` and `CACHE_MAX_TTL` clamps. It does not override the rules of the previous section: a response with `Set-Cookie` or `Cache-Control: no-store` is still not stored.
- A rule with TTL `-1` makes requests for that path ineligible for the cache, so they are fetched with the visitor's own headers and cookies.
- A rule match also allows negative caching of `404` for URLs with a query string.
- The last `=` separates glob and TTL, so globs containing `=` work.

## Purging

An entry can be removed three ways: by CLI, through the admin endpoint, or by waiting.

Targets are paths as the visitor would request them, for example `/about` or `/search?q=php`. MirrorGate normalises the target the same way as an incoming request (percent-encoding case, dot segments, ignored query parameters), so `/a/./b` and `/a/b` are the same target. If nothing matched, the admin response includes a warning.

### CLI

```bash
php bin/mirrorgate purge --url=/about            # one URL (all query variants are separate entries)
php bin/mirrorgate purge --prefix=/blog/         # everything below a prefix
php bin/mirrorgate purge --all                   # the whole cache of this instance
php bin/mirrorgate purge --prefix=/blog/ --soft  # soft purge
```

Use `--env=/path/to/.env` when the file is not in the project root.

### Admin endpoint

Enable the admin (`ADMIN_ENABLED=true`, a strong `ADMIN_TOKEN`, an `https` mirror; see [security.md](security.md#admin-endpoint)), then:

```bash
curl -sS -X POST https://ormantek.com/__mirrorgate/purge \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"mode":"url","target":"/about","soft":false}'
```

`mode` is `url`, `prefix` or `all` (`target` is required except for `all`). The reply is `{"purged": <count>}`. The request body must be JSON with the content type `application/json`.

Other read-only endpoints: `GET /__mirrorgate/stats` returns the entry count, size, purge generation and circuit breaker state; `GET /__mirrorgate/health` returns a short summary; `GET /__mirrorgate/live` returns `ok`.

### Soft purge

A **hard** purge deletes the entries. A **soft** purge keeps them but marks them as needing revalidation: the next request asks the origin (conditionally, when the entry has a validator) and renews or replaces the entry. Soft purge is the better tool for "the content changed, but keep the safety net": if the origin is down during revalidation and the entry is still inside its stored windows, the old copy can still be served.

### Purging multiple instances

Purges act on one instance. Run the command for every instance that serves the content (see [multi-instance.md](multi-instance.md)).

## Garbage collection and limits

- **Limits.** `CACHE_MAX_BYTES` (total), `CACHE_MAX_ENTRIES` (file count, protects inode quotas) and `CACHE_MAX_OBJECT_BYTES`. When the entry limit is reached, new entries are refused after one bounded collection pass. Above 95 percent of a limit, collection evicts the oldest entries down to 90 percent.
- **Collection** removes expired entries (beyond their latest window, plus one hour when a validator allows conditional revalidation), entries of an older purge generation, corrupt files, orphaned temporary files older than 10 minutes, old lock files, and orphaned index files.
- **When it runs.** After about `CACHE_GC_PROBABILITY` percent of responses (default 1), at most every `CACHE_GC_INTERVAL` seconds (default 300), after the response has been sent. Each of these runs is bounded to about 200 milliseconds or 2000 files and continues where the previous run stopped. `php bin/mirrorgate gc` runs a pass with a 5 second budget by hand, and `bin/mirrorgate-gc` (default budget 20 seconds, `--budget-ms=N` to change) is meant for cron:

  ```
  */15 * * * * MIRRORGATE_ENV=/home/user/instances/ormantek/.env /usr/local/bin/php /home/user/mirrorgate/bin/mirrorgate-gc --quiet
  ```

  Set `CACHE_GC_PROBABILITY=0` to rely on cron alone.

## Tuning recipes

**A mostly static brochure site.** Longer lifetimes and strip tracking parameters:

```ini
CACHE_DEFAULT_TTL=900
CACHE_MAX_TTL=604800
CACHE_IGNORE_QUERY_PARAMS=utm_source,utm_medium,utm_campaign,utm_term,utm_content,fbclid,gclid
CACHE_RULES=/assets/*=604800,/images/*=604800
```

**A site with login-dependent pages.** Do not cache pages for visitors who carry cookies, never cache the API and the account area:

```ini
CACHE_COOKIE_MODE=bypass
CACHE_RULES=/api/*=-1,/account/*=-1
AUTH_PASSTHROUGH_BLOCK=/login*,/oauth/*,/signin*
```

**A small shared hosting plan.** Keep the footprint low:

```ini
CACHE_MAX_BYTES=64M
CACHE_MAX_ENTRIES=5000
CACHE_MAX_OBJECT_BYTES=2M
CACHE_COALESCE_WAIT_MS=500
```

**No caching at all** (every request goes to the origin; rewriting still applies):

```ini
CACHE_ENABLED=false
```

## Troubleshooting the cache

| Symptom | Check |
|---|---|
| Always `MISS` | The origin sends `Set-Cookie`, `Cache-Control: private` or `no-store`, or `Vary: Cookie`/`*`. The request carries a cookie and the path is not a static extension. A `CACHE_RULES` entry has TTL `-1`. The cache directory is not writable (`bin/mirrorgate check` shows `cache.writable`). Use `LOG_LEVEL=debug` for hints |
| `BYPASS` on pages that should be cached | The response was too large, the request had `Authorization`, `Range` or an RSC header, or the origin's `Vary` names a header that is not in `CACHE_VARY_EXTRA` |
| Different visitors see each other's content | Should not happen with the defaults, since fills are anonymous and `Set-Cookie`/`Vary: Cookie` responses are not stored. Check whether the origin personalises by something other than cookies (for example by IP) and use `CACHE_RULES` with `-1` for those paths |
| Stale content stays for too long | Lower `CACHE_MAX_TTL`, or purge the path (soft purge to keep the safety net) |
| Stale-while-revalidate never refreshes in the background | The PHP SAPI cannot detach the connection; see `sapi.detach` in the self check |
| Disk or inode quota exceeded | Lower `CACHE_MAX_BYTES` and `CACHE_MAX_ENTRIES`, run `mirrorgate-gc` from cron, or purge with `--all` |
