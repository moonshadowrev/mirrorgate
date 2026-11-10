# Running several instances

MirrorGate mirrors exactly one origin host onto one mirror host (plus optional aliases). To mirror several sites, or several subdomains of one site, run several independent **instances**: one per mirror (sub)domain. This page explains how to lay them out on one shared hosting account and how siblings that link to each other are kept consistent.

## The model

1. One instance = one mirror host = one document root = one environment file = one `STORAGE_DIR`.
2. Instances never share cache, locks, rate-limit counters, the circuit breaker state or logs. Do not point two instances at the same `STORAGE_DIR`.
3. Wildcard mirroring (`*.overload.ir`) is not supported. Every host you want to serve is configured explicitly, with its own `.env`.
4. Instances on one account still share the account's resources: the PHP worker pool, disk quota and inode quota. `CONCURRENCY_SHARED_DIR` lets them share one origin-concurrency cap (see [Account-wide limits](#account-wide-limits)).
5. Instances that link to each other tell each other about it with `HOST_MAP`, so cross-links, redirects, CORS headers and cookies keep working across the mirrored hosts.

## Choose a code layout

### Layout 1: full copy per instance

Upload a complete release into a separate folder for each domain, set that domain's document root to the folder's `public/`, and put the `.env` one level above `public/`.

```
/home/user/
  overload.ir/                 release copy 1
    .env
    public/                    document root of overload.ir
    src/ bin/ templates/ var/
  api.overload.ir/             release copy 2
    .env
    public/                    document root of api.overload.ir
    src/ bin/ templates/ var/
```

Pros: the simplest possible setup, each instance isolated, trivial to understand. Cons: every update has to be uploaded once per instance and each copy uses its own inodes for source files.

Use it when you can set document roots freely and you run few instances.

### Layout 2: shared code with a stub per domain

Upload the code once. Give each instance a folder that holds its `.env` and runtime data, and give each domain a tiny stub in its document root.

```
/home/user/
  mirrorgate/                          shared code, uploaded once
    src/ bin/ public/ templates/
  instances/
    overload/    .env  var/            instance 1
    api/         .env  var/            instance 2
    cdn/         .env  var/            instance 3
  overload.ir/       (document root)
    index.php  .htaccess  .user.ini
  api.overload.ir/   (document root)
    index.php  .htaccess  .user.ini
  cdn.overload.ir/   (document root)
    index.php  .htaccess  .user.ini
```

Each stub `index.php` is three lines:

```php
<?php
const MIRRORGATE_ENV = '/home/user/instances/api/.env';
require '/home/user/mirrorgate/public/index.php';
```

Copy `mirrorgate/public/.htaccess` and `mirrorgate/public/.user.ini` into each document root; they route requests to the stub and set `enable_post_data_reading = 0`. `MIRRORGATE_ROOT` then resolves to `/home/user/mirrorgate`, and `STORAGE_DIR` defaults to the `var/` folder next to each `.env`, so every instance gets its own runtime directory automatically.

Pros: one place to update, minimal inode use for source files, secrets and data outside every web root. Cons: three extra files per domain.

Use this layout for three or more instances, or whenever you cannot choose document roots freely but can place files in them.

### Layout 3: the root shim

If an instance's document root is fixed and contains the whole project (see [deployment-shared-hosting.md](deployment-shared-hosting.md#layout-c-in-detail)), that instance needs `.env.php` and a `STORAGE_DIR` outside the web root. This layout does not scale to many instances because each would need a full copy of the project inside its document root. Prefer layout 2.

## Per-domain settings

Do these for every instance.

| Item | What to do |
|---|---|
| DNS | An `A` (and `AAAA`) record for the mirror host. A wildcard record is fine at the DNS level, but each host still needs its own instance and document root |
| Document root | One folder per host (layouts 1 and 2) |
| PHP version | Select 8.3 or newer for each domain separately (MultiPHP Manager or the PHP selector). A domain keeps its own PHP version |
| `.user.ini` | One per document root. It applies to the scripts in that directory |
| HTTPS | AutoSSL (cPanel) or Let's Encrypt (DirectAdmin) issues a certificate per host. Run it after DNS points at the server |
| `.env` | Own `ORIGIN_URL` and `MIRROR_URL`. Own `ADMIN_TOKEN` if the admin is enabled. Leave `APP_INSTANCE_ID` unset (the default is derived from the host pair) or set unique values |
| Cron (optional) | One garbage-collection job per instance, with `MIRRORGATE_ENV` pointing at that instance's file (see below) |

Garbage collection with several instances:

```
*/15 * * * * MIRRORGATE_ENV=/home/user/instances/overload/.env /usr/local/bin/php /home/user/mirrorgate/bin/mirrorgate-gc --quiet
*/15 * * * * MIRRORGATE_ENV=/home/user/instances/api/.env      /usr/local/bin/php /home/user/mirrorgate/bin/mirrorgate-gc --quiet
*/15 * * * * MIRRORGATE_ENV=/home/user/instances/cdn/.env      /usr/local/bin/php /home/user/mirrorgate/bin/mirrorgate-gc --quiet
```

## Example: `overload.ir`, `api.overload.ir` and `cdn.overload.ir`

The origin is `overlord.team`, which has an API on `api.overlord.team` and static assets on `cdn.overlord.team`. The site's pages call the API from the browser and load assets from the CDN host. You want all three hosts to be served by the mirror.

### Instance 1: the main site (`overload.ir`)

`/home/user/instances/overload/.env`:

```ini
ORIGIN_URL=https://overlord.team
MIRROR_URL=https://overload.ir
MIRROR_ALIASES=www.overload.ir

# Siblings served by the other instances
HOST_MAP=api.overlord.team=api.overload.ir,cdn.overlord.team=cdn.overload.ir

# Cookies set for .overlord.team should be visible on the mirror siblings too
COOKIE_DOMAIN_MODE=rewrite

LOG_LEVEL=info
```

### Instance 2: the API (`api.overload.ir`)

`/home/user/instances/api/.env`:

```ini
ORIGIN_URL=https://api.overlord.team
MIRROR_URL=https://api.overload.ir

# Reciprocal entry: the main site, so Access-Control-Allow-Origin from the API
# (which names https://overlord.team) is mapped to https://overload.ir
HOST_MAP=overlord.team=overload.ir
COOKIE_DOMAIN_MODE=rewrite

# An API is rarely worth caching blindly
CACHE_RULES=/*=-1
LOG_LEVEL=info
```

### Instance 3: the CDN (`cdn.overload.ir`)

`/home/user/instances/cdn/.env`:

```ini
ORIGIN_URL=https://cdn.overlord.team
MIRROR_URL=https://cdn.overload.ir

HOST_MAP=overlord.team=overload.ir
CACHE_MAX_BYTES=512M
CACHE_MAX_OBJECT_BYTES=20M
LOG_LEVEL=info
```

### Shared concurrency (optional)

To enforce one origin-concurrency cap for all three instances, add the same lines to each `.env`:

```ini
CONCURRENCY_SHARED_DIR=/home/user/mirrorgate-shared
GLOBAL_MAX_CONCURRENT=8
```

### Checking the result

Run the check for each instance. With shell access:

```bash
php /home/user/mirrorgate/bin/mirrorgate check --env=/home/user/instances/overload/.env
```

Besides the normal checks it prints one line per `HOST_MAP` entry telling you which line the sibling instance must contain, for example:

```
[OK  ] hostmap.reciprocal: The sibling instance that mirrors api.overlord.team as api.overload.ir should list in HOST_MAP: overlord.team=overload.ir
```

It also warns (`hostmap.parent`) when a mapped mirror host does not share a parent domain with `MIRROR_URL`, and fails (`hostmap.collision`) when a mapping targets a host that is itself an origin host. Without shell access, `POST /__mirrorgate/check` returns the same information with a generic reciprocal-lines reminder. The check cannot read the sibling `.env` files, so it cannot tell whether they are actually consistent. You have to make them so.

## Making siblings consistent

### `HOST_MAP` must be reciprocal

`HOST_MAP` entries are `originhost=mirrorhost`. Every instance lists the other mirrored hosts it needs to know about:

- Instance 1 (`overlord.team` as `overload.ir`) lists `api.overlord.team=api.overload.ir`. Links to the API found in its pages are rewritten to the mirror.
- Instance 2 (`api.overlord.team` as `api.overload.ir`) lists `overlord.team=overload.ir`. When the API answers with `Access-Control-Allow-Origin: https://overlord.team`, the header is mapped to `https://overload.ir`. Without the entry the header would be dropped and the browser would block cross-origin calls from the main site to the API.

If you forget the reverse entry, the symptoms are blocked CORS requests, redirects that leave the mirror, and absolute URLs in API responses that still name the origin.

### What `HOST_MAP` may contain

The origin side of an entry must be the instance's own origin host, a subdomain of it, or a parent domain of it that has at least two labels (`overlord.team` is a valid entry for the instance whose origin is `api.overlord.team`). The mirror side for the instance's own origin host must equal the host of `MIRROR_URL`. Entries naming a sibling subdomain are accepted only by instances whose origin is the parent domain.

This has a consequence worth knowing: in the example above, the API instance cannot list `cdn.overlord.team` (its origin is `api.overlord.team`, and `cdn.overlord.team` is neither a subdomain nor a parent of it). `bin/mirrorgate` rejects such an entry with `HOST_MAP:origin_side_not_related`. Links from API responses to `cdn.overlord.team` are therefore not rewritten and keep pointing at the origin's CDN host. If your API returns such absolute URLs and the browser must stay on the mirror, restructure so that the instance whose origin is the parent domain serves those responses, or accept that those URLs go to the origin directly.

### Cookies

By default (`COOKIE_DOMAIN_MODE=drop`) the `Domain` attribute of every origin cookie is removed, so the cookie becomes host-only on the mirror host that received it. A cookie that the origin scoped to `.overlord.team` is then **not** shared between `overload.ir` and `api.overload.ir`, and API authentication that relies on the shared cookie breaks.

`COOKIE_DOMAIN_MODE=rewrite` maps the origin's `Domain` to the mirror host: a cookie with `Domain=.overlord.team` becomes `Domain=overload.ir`, provided the browser would accept it for the host that is being served and the origin domain is one the instance knows (its own host, a `HOST_MAP` entry, or the parent entry from the reciprocal map). In the example this is why the API instance needs `overlord.team=overload.ir`: without that entry the cookie's `Domain=.overlord.team` cannot be mapped and the attribute is dropped.

The price of `rewrite` is cookie tossing: a cookie scoped to the parent mirror domain is visible to every other subdomain of `overload.ir`, including any unrelated site you host there. Only use it when all subdomains of the mirror parent domain are under your control. Cookies are always confined by `Secure`, `HttpOnly` and `SameSite` as set by the origin (`Secure` is added when the mirror is `https` and `COOKIE_FORCE_SECURE=true`).

### Prefix mode is not a substitute

`SUBDOMAIN_MODE=prefix` with `EXTRA_ORIGINS` serves several origin hosts through one mirror host and one certificate, under `/__mg/s/<origin host>/`. It merges all those hosts into a single browser origin, so cookies, local storage and script access are shared between what used to be separate sites. Boot requires `PREFIX_MODE_ACK_SHARED_ORIGIN=true`, and the self check reports the setting as `FAIL`. One instance per subdomain with `HOST_MAP` is the recommended design.

## Account-wide limits

All instances on an account share the PHP worker pool. The per-IP limits (`RATE_LIMIT_REQUESTS`, `RATE_LIMIT_MAX_CONCURRENT`) and the stream cap (`MAX_CONCURRENT_STREAMS`) are counted per instance, because their state lives in each instance's `STORAGE_DIR`. Three instances with a per-IP concurrency of 4 can still keep 12 workers busy for one client.

Two settings help:

- `GLOBAL_MAX_CONCURRENT` (default 8) caps how many requests do origin I/O at the same time. Cache hits and stale responses do not take a slot. Over the cap, visitors get `503` with `Retry-After: 1`.
- `CONCURRENCY_SHARED_DIR` makes instances that point at the same directory share one pool of those slots, which turns the cap into an account-wide cap. Use an absolute directory outside the web root, writable by PHP. All instances that share it should use the same `GLOBAL_MAX_CONCURRENT` value, because each instance compares the shared count against its own configured maximum. Slots are leases that expire after 120 seconds, so a crashed request never blocks the pool permanently.

Choose `GLOBAL_MAX_CONCURRENT` below the number of PHP workers your plan allows, so visitors who would have to wait get a quick `503` instead of tying up every worker. Because cache hits do not take a slot, a warm cache keeps the site responsive even when the origin is slow.

## Cache directories

By default each instance caches in `STORAGE_DIR/cache`. That is the safest setup.

If you move the cache elsewhere with `CACHE_DIR`, you may point several instances at the same directory: MirrorGate stores each instance in its own subdirectory `<CACHE_DIR>/<APP_INSTANCE_ID>/`. Two things must hold:

- `APP_INSTANCE_ID` must be different for every instance. The default (derived from the origin and mirror host) already is. If you set it manually, make sure no two instances use the same value; otherwise they would read each other's entries. Cache keys also include a fingerprint of the rewrite settings, origin and mirror URLs and host map, so instances with the same ID but different configuration still never share variants, but do not rely on that.
- Limits are enforced per instance. `CACHE_MAX_BYTES` and `CACHE_MAX_ENTRIES` apply to each instance's own subdirectory, so the total disk and inode use is the sum across instances.

Do not share `STORAGE_DIR`: the circuit breaker state, rate-limit counters and the generated salt live there and would be mixed between instances.

## Disk and inode budget

Plan the quota for the whole account:

| Item | Roughly |
|---|---|
| Cached objects | About two files per object (entry plus index), up to `CACHE_MAX_ENTRIES` entries per instance |
| Rate limiter | Up to 4096 small shard files per instance when the file driver is used (none with APCu) |
| Locks and state | A few hundred small files per instance |
| Logs | `LOG_MAX_BYTES` (5M) plus one rotated copy per log |

Reduce `CACHE_MAX_ENTRIES` for instances that serve little and keep a larger value only where it pays off, for example for a CDN host.

## Operating many instances

- **Updates.** Layout 2: replace the shared `mirrorgate` folder once. Layout 1: replace each copy. Keep every `.env` and runtime directory.
- **Checks.** Run `bin/mirrorgate check` for each instance after changes. `config:dump` prints the effective configuration with secrets masked.
- **Admin.** If you enable the admin endpoint, give every instance its own token and restrict it with `ADMIN_IP_ALLOW`.
- **Purging.** The CLI `bin/mirrorgate purge` and the admin `/purge` act on one instance only. Purge every instance that serves the affected content.
- **Debugging a single host.** Each instance logs to its own `STORAGE_DIR/logs`. Set `LOG_LEVEL=info` on the instance in question to get the access log.
