# Deploying on shared hosting (cPanel and DirectAdmin)

This guide installs MirrorGate on ordinary shared hosting using only a browser and the file manager or FTP. No shell access, Composer or root rights are needed ("upload-only"). The examples mirror `overlord.team` as `overload.ir`.

Menu names differ between cPanel and DirectAdmin versions, themes and hosting providers. Treat the click paths below as a guide and look for the equivalent option in your panel.

To run several domains or subdomains from one account, read this page first and then [multi-instance.md](multi-instance.md).

## Before you start

Check these points with your hosting provider or in the panel.

| Item | Why it matters |
|---|---|
| PHP 8.3 or newer can be selected for the domain | MirrorGate refuses to run on older versions |
| `curl`, `mbstring` and `json` are enabled (`zlib` and `intl` are recommended) | Required and optional extensions |
| PHP is allowed to open outbound HTTPS connections | The mirror cannot work otherwise. Some hosts filter outbound traffic, particularly to foreign networks. See [troubleshooting.md](troubleshooting.md#the-origin-is-unreachable-from-the-host) |
| `curl_exec` and `curl_multi_exec` are not in `disable_functions` | `bin/mirrorgate check` and the admin `/check` report this as `php.disable_functions` |
| The provider's terms allow reverse-proxy or mirror scripts | Some providers forbid them. Read the acceptable use policy before you start |
| You can create a domain or subdomain, or change its document root | Decides which layout below you can use |
| `memory_limit` of 128M or more | Rewriting a body needs roughly four times its size in free memory. If it does not fit, the body is passed through unrewritten and not cached |
| A DNS record for the mirror domain pointing at the server | Needed for HTTPS issuance |

## Choose a layout

MirrorGate must be served from a document root that contains only `index.php` and a few rule files. The environment file, the source and the runtime data must not be reachable by URL. Pick the first option your hosting allows.

| | Layout | When to use it | Needs |
|---|---|---|---|
| A | Document root points at `mirrorgate/public` | You can set the document root of the domain to any folder | Nothing else. Best choice |
| B | Small stub document root, code and `.env` kept elsewhere | The domain gets its own folder under the web root, but it cannot point into `public/`. Also the usual choice for many instances | A three-file stub per domain |
| C | Root shim: the whole project sits in the document root | The document root is fixed (for example `public_html`) and you do not want a stub | `.env.php` and `STORAGE_DIR` outside the web root. Protection no longer depends on `.htaccess` alone |

In all layouts the files that must never be web-readable are the environment file, `src/`, `bin/`, `templates/`, `vendor/` and the runtime directory (`var/` or whatever `STORAGE_DIR` is).

Layouts B and C exist because many hosts do not let you select an arbitrary folder as the document root. Layout A is the simplest and safest.

## Step 1: prepare the package

Download the release archive (or build one from the repository, for example with `git archive`) and unzip it on your computer. You do not need `vendor/`, `tests/`, `docs/` or `.github/` on the server.

Create a `.env` file from `.env.example`. At minimum:

```ini
ORIGIN_URL=https://overlord.team
MIRROR_URL=https://overload.ir
LOG_LEVEL=info
```

Add `MIRROR_ALIASES=www.overload.ir` if the `www` host should work too, and review [configuration.md](configuration.md). Do not put the file into any folder that is served by the web server (layouts A and B) or name it `.env.php` (layout C).

## Step 2 (cPanel): PHP version and extensions

1. Open **MultiPHP Manager** (or **Select PHP Version** on CloudLinux hosts), choose the domain and select PHP 8.3 or newer. For layout A and B, do this for the domain or subdomain that will serve the mirror.
2. In **Select PHP Version** (or **MultiPHP INI Editor**) make sure `curl`, `mbstring` and `json` are enabled. Enable `zlib` and `intl` if offered. If APCu is offered, enable `apcu` for faster rate limiting.
3. Check `memory_limit` and `max_execution_time` in the INI editor. `post_max_size` should be at least as large as `MAX_REQUEST_BODY_BYTES` (default 10M); if it is smaller, it silently becomes the effective upload limit.

## Step 3 (cPanel): upload the files

Layout A or B:

1. Open **File Manager** and go to your home directory, **above** `public_html`.
2. Upload the zip, extract it into a folder named `mirrorgate`, and delete the zip. Enable "Show hidden files (dotfiles)" in the File Manager settings; you will need it for `.env` and `.htaccess`.
3. Layout A: continue with step 4. Layout B: continue with [Layout B in detail](#layout-b-in-detail).

Layout C: extract the whole project directly into the document root (for example `public_html` or the folder of an addon domain), then continue with [Layout C in detail](#layout-c-in-detail).

## Step 4 (cPanel, layout A): create the domain with the right document root

1. Open **Domains** (older versions: **Addon Domains** or **Subdomains**).
2. Create the domain or subdomain. When asked for the document root, enter the path of the `public` directory, for example `mirrorgate/public`. Do not tick "Share document root".
3. If the domain already exists, use the **Manage** action on **Domains** to change its document root to `mirrorgate/public`. The main domain of the account often cannot be changed; use layout B or C for it.
4. Create the file `mirrorgate/.env` in File Manager (it must sit **one level above** `public/`) and paste your configuration. Set its permissions to `0640`, or `0600` if the PHP handler runs as your own account user. On a host where PHP runs as the shared web-server user (Apache `mod_php`, see [below](#file-permissions)), use `0644` and set `STORAGE_FILE_MODE=0644` and `STORAGE_DIR_MODE=0755`.

## Layout B in detail

Layout B keeps the shared code and the configuration outside the web root and gives each domain a stub. Directory structure:

```
/home/user/
  mirrorgate/                   code, uploaded once (src/, bin/, public/, templates/ ...)
  instances/
    overload/
      .env                      configuration of this mirror
      var/                      created automatically: cache, logs, state
  public_html/                  or the folder of the domain
    index.php                   the stub (below)
    .htaccess                   copy of mirrorgate/public/.htaccess
    .user.ini                   copy of mirrorgate/public/.user.ini
```

1. Create `instances/overload/.env`.
2. In the document root of the domain create `index.php`:

   ```php
   <?php
   const MIRRORGATE_ENV = '/home/user/instances/overload/.env';
   require '/home/user/mirrorgate/public/index.php';
   ```

   Use the real absolute path of your home directory (the panel shows it, usually in the sidebar of the File Manager).
3. Copy `mirrorgate/public/.htaccess` and `mirrorgate/public/.user.ini` into the same document root. These two files carry the rewrite rules, the deny rules and the `enable_post_data_reading = 0` setting (see [PHP settings](#php-settings-that-matter)).
4. `STORAGE_DIR` defaults to `/home/user/instances/overload/var`, next to the `.env`. Nothing writable is inside the web root.

`MIRRORGATE_ROOT` resolves to `/home/user/mirrorgate` (the directory above the `public/` folder of the required file), so templates and source are found there. This was verified with PHP's built-in server using exactly this stub.

When the document root of the domain already contains other things, remember that the `.htaccess` sends **every** request, including requests for existing files, to MirrorGate (except real non-PHP files under `.well-known/` and `static/`). The domain is a dedicated mirror; do not mix other sites into the same folder.

## Layout C in detail

Layout C puts the entire project into the document root. This is the weakest layout because `src/`, `bin/` and the rest are inside the web root and only rules stop a download. Use it only if layouts A and B are impossible.

1. Extract the project into the document root. The root already contains the shim files `index.php`, `.htaccess`, `.user.ini` and `web.config`.
2. Do **not** create a plain `.env`. Create `.env.php` in the project root. Its first line must be exactly:

   ```
   <?php http_response_code(404); exit; ?>
   ```

   followed by the configuration. If the web server ever executes this file, it answers `404` and prints nothing.
3. Set `STORAGE_DIR` to a directory **outside** the document root, for example `STORAGE_DIR=/home/user/mirrorgate-data/overload`. If `STORAGE_DIR` stays inside the document root, MirrorGate refuses to boot and logs `STORAGE_DIR:in_docroot`.
4. Verify from the outside that these URLs do not return project content: `https://overload.ir/.env`, `/.env.php`, `/src/autoload.php`, `/composer.json`. The first three are probed by `check --network` and by the admin `/check` endpoint.

On LiteSpeed without `AllowOverride`, on nginx, or when `.htaccess` is ignored for any reason, every file in the project could be downloadable. That is why `.env.php` and an external `STORAGE_DIR` are mandatory in this layout. If you can, prefer layout B.

## PHP settings that matter

MirrorGate needs `enable_post_data_reading = 0`. With PHP's default of `1`, PHP consumes form and multipart bodies and `php://input` is empty, so uploads would be forwarded empty. MirrorGate detects this and answers `500` (log code `INI:enable_post_data_reading`) instead of forwarding an empty body.

The setting is shipped in these places:

| Handler | Where it is set |
|---|---|
| PHP-FPM, FastCGI, CGI, LiteSpeed LSAPI | `.user.ini` in the document root. PHP caches it for `user_ini.cache_ttl` (300 seconds by default), so allow five minutes after a change |
| Apache `mod_php` | `.htaccess` (`php_value enable_post_data_reading 0`) |
| nginx | `fastcgi_param PHP_VALUE "enable_post_data_reading=0";` in the vhost, see [deployment-vps.md](deployment-vps.md) |

If your handler honours neither file, ask the host to set `enable_post_data_reading=0` for the domain. `bin/mirrorgate check` and the admin `/check` report `ini.enable_post_data_reading` as `OK` or `FAIL`. From the command line the value shown may differ from the web value, so run the check through the admin endpoint when in doubt.

Other settings worth reviewing:

| Setting | Recommendation |
|---|---|
| `post_max_size` | Not smaller than `MAX_REQUEST_BODY_BYTES`. Uploads above it are rejected with `413` |
| `memory_limit` | 128M or more |
| `max_execution_time` | 30 seconds or more. If `set_time_limit()` is disabled, streaming and SSE are clamped to about `max_execution_time - 5` seconds |
| `disable_functions` | Must not contain `curl_init`, `curl_setopt`, `curl_exec`, `curl_multi_exec`. `set_time_limit`, `ini_set` and `fastcgi_finish_request` may be disabled; MirrorGate degrades instead of failing |
| `open_basedir` | If set, `STORAGE_DIR` (and `CONCURRENCY_SHARED_DIR`) must be inside it |

## File permissions

| PHP handler | `.env` | `STORAGE_FILE_MODE` | `STORAGE_DIR_MODE` |
|---|---|---|---|
| PHP-FPM, suPHP, LiteSpeed LSAPI, CloudLinux CageFS: PHP runs as your account | `0600` or `0640` | `0640` (default) or `0600` | `0750` (default) |
| Apache `mod_php` where every account's PHP runs as the same web-server user | `0644` | `0644` | `0755` |

With the second row other accounts on the same server may be able to read your `.env` through their own PHP. `bin/mirrorgate check` warns about `mod_php` without evidence of per-account isolation (`php.sapi_isolation`). Prefer PHP-FPM or LiteSpeed with per-account users, keep secrets in `.env.php`, and do not mirror authenticated applications on such a host.

## Step 5 (cPanel): HTTPS with AutoSSL

1. Make sure the DNS record of the domain points at the server.
2. Open **SSL/TLS Status** and run **AutoSSL** for the domain (or let the nightly run do it).
3. AutoSSL validates domain control by placing a file under `/.well-known/` in the document root and fetching it over HTTP. The shipped `.htaccess` rules serve real, non-PHP files under `.well-known/` directly from the document root, and requests below `/.well-known/acme-challenge/` are never sent to the origin (MirrorGate answers them from the document root or with a local `404`). Validation therefore works in layouts A, B and C. Other `/.well-known/` paths for which no real file exists (for example `security.txt`) are proxied to the origin like any other path. If you use a CDN or proxy in front of the domain, it must pass `/.well-known/` through, or issue the certificate there.
4. After the certificate is installed, `MIRROR_URL` must use `https://`.

## Step 6 (cPanel): optional cache garbage collection

The cache cleans itself in small steps after responses (`CACHE_GC_PROBABILITY`, `CACHE_GC_INTERVAL`). On a busy mirror or a small inode quota, also add a cron job (**Cron Jobs**):

```
*/15 * * * * MIRRORGATE_ENV=/home/user/instances/overload/.env /usr/local/bin/php /home/user/mirrorgate/bin/mirrorgate-gc --quiet
```

Notes:

- Use the PHP binary path of your host. In a cPanel terminal `which php` shows the default; the MultiPHP binary for a specific version is often under `/opt/cpanel/ea-php83/root/usr/bin/php`. The command-line PHP version must be 8.3 or newer.
- `mirrorgate-gc` has no `--env` option. It reads `.env.php` or `.env` in the project root unless the `MIRRORGATE_ENV` process variable is set, as above (needed for layout B).
- `--budget-ms=N` limits the run time (default 20000).
- The tool is incremental and safe to run often.

## DirectAdmin

The principles are identical. Typical steps:

1. **PHP version.** Open **Domain Setup** (or **PHP Version Selector**, depending on the skin) and choose PHP 8.3 or newer for the domain. On CloudLinux hosts use **Select PHP Version** to enable `curl`, `mbstring`, `json`, and optionally `zlib`, `intl`, `apcu`.
2. **Domain or subdomain.** Create it under **Domain Setup** or **Subdomain Management**. DirectAdmin normally gives each domain `domains/<domain>/public_html` as its document root. If your plan or version lets you change the document root, point it to `.../mirrorgate/public` (layout A). If not, use layout B and place the stub in `domains/<domain>/public_html`, with the code and `.env` somewhere in your home directory outside `domains/`.
3. **Upload.** Use **File Manager** or FTP. Show hidden files to see dotfiles. Upload the package to a folder in your home directory, outside any `public_html`.
4. **HTTPS.** Use **SSL Certificates** to request a Let's Encrypt certificate for the domain (and the `www` alias if you use it). Validation needs `/.well-known/acme-challenge/` to be reachable, which the shipped rules allow.
5. **Cron.** Use **Cron Jobs** with the same command as in step 6 above. Use the PHP binary path shown by your host.

## Step 7: first start and verification

1. Request `https://overload.ir/` in a browser. You should see the origin site with links on the mirror host.
2. Look at the response headers (browser developer tools or `curl -I https://overload.ir/`). `X-MirrorGate-Cache: MISS` on the first request and `HIT` on the second shows that the cache works. `Via: 1.1 mirrorgate` is present unless you set `ADD_VIA=false`.
3. A generic `500` page with a request ID means boot failed. Read the bootstrap log; it lists configuration problems by code. See [troubleshooting.md](troubleshooting.md#reading-the-logs).
4. Run the self check. With shell access: `php bin/mirrorgate check --network`. Without shell access, use the admin endpoint described next.

### Self check without shell access

1. Add to `.env`, choosing your own random token (32 or more characters):

   ```ini
   ADMIN_ENABLED=true
   ADMIN_TOKEN=<64 hex characters from a password generator>
   ADMIN_IP_ALLOW=<your own public IP address>
   ```

   Generate a token locally with `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`. The admin requires an `https://` `MIRROR_URL`.
2. Request the check from your machine:

   ```bash
   curl -sS -X POST -H "Authorization: Bearer $TOKEN" https://overload.ir/__mirrorgate/check
   ```

   If your host strips the `Authorization` header (some FastCGI setups), use `-H "X-MirrorGate-Token: $TOKEN"` instead.
3. The response is JSON with one entry per check (`name`, `level` of `ok`, `warn` or `fail`, and a message with paths and host names removed). Fix every `fail`.
4. Set `ADMIN_ENABLED=false` again, or at least keep `ADMIN_IP_ALLOW` tight. Any wrong request to the admin path, including an unknown token, is answered with the same `404` as a missing page.

When the admin is disabled, `/__mirrorgate/...` is an ordinary path and is proxied to the origin.

## Updating

1. Upload the new release over the old one (layout A and C: the project folder; layout B: the shared `mirrorgate` folder). Keep your `.env` or `.env.php` and the runtime directory.
2. Do not delete `var/` or `STORAGE_DIR` unless you want to clear the cache.
3. Changing rewrite-related settings or the version of the rewriter changes the cache fingerprint, so older cache entries are not served any more. You do not have to purge manually. See [caching.md](caching.md#cache-keys-and-fingerprints).
4. Run `check` again.

Note on a fresh upload: it contains no deny guards inside `var/`: MirrorGate creates `var/.htaccess`, `var/index.html` and `var/logs/.htaccess` on the first run. Until then, rely on the root `.htaccess` rules (or better, set `STORAGE_DIR` outside the web root) and trigger one request or `bin/mirrorgate check` right after uploading.

## Quotas and limits to watch

- **Inodes (file count).** Each cached object uses an entry file plus a small index file, and the limiter and lock directories contain up to a few thousand small state files. Lower `CACHE_MAX_ENTRIES` (default 20000) if your quota is tight.
- **Disk.** `CACHE_MAX_BYTES` defaults to 256M. Logs rotate once at `LOG_MAX_BYTES` (5M).
- **PHP workers.** Shared plans often allow 5 to 20 simultaneous PHP processes for the whole account. A slow origin can occupy all of them. The defaults (`RATE_LIMIT_MAX_CONCURRENT=4`, `GLOBAL_MAX_CONCURRENT=8`, `MAX_CONCURRENT_STREAMS=3`) are chosen for that. Lower them if the account has fewer workers.
- **Temporary files.** Request bodies larger than 1 MiB are spooled to PHP's temporary directory while they are forwarded.

## Common mistakes

| Symptom | Likely cause |
|---|---|
| Generic `500` with a request ID on every page | Configuration error or a boot-time refusal. Read the bootstrap log |
| `421` for every request | The `Host` header is not `MIRROR_URL`'s host or in `MIRROR_ALIASES` (for example the `www` host) |
| Forms and uploads arrive empty at the origin | `enable_post_data_reading` is not 0 for the web handler |
| Everything is a `502` or `504` | The host cannot reach the origin. See the troubleshooting guide |
| Login does not work | The mirror host differs from the origin host, which breaks cookie-bound sessions, OAuth callbacks and CSRF checks. Do not mirror authenticated applications |
| The same page is cached for every visitor | A cookie-dependent page was cached. Set `CACHE_COOKIE_MODE=bypass` and add `CACHE_RULES` with ttl `-1` for dynamic paths |
