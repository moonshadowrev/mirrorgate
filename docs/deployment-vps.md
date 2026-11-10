# Deploying on a VPS or dedicated server

On a server you control, MirrorGate runs behind nginx or Apache with PHP-FPM, and you can also run the long-lived [daemon](daemon.md) for WebSocket, gRPC, TCP and UDP. This page covers the web server side. For shared hosting see [deployment-shared-hosting.md](deployment-shared-hosting.md).

Ready-to-adapt configuration files ship in `examples/`:

| File | Purpose |
|---|---|
| `examples/nginx.conf` | nginx and PHP-FPM, one server block per instance |
| `examples/apache-vhost.conf` | Apache 2.4 with `mod_proxy_fcgi` and PHP-FPM |
| `examples/mirrorgate-daemon.service` | systemd unit for the daemon |

## What every web server setup must do

MirrorGate relies on the web server for a few things. Whatever server you use, make sure it does all of them.

1. **Document root is `public/`.** The environment file, `src/`, `bin/`, `templates/`, `vendor/` and the runtime directory live one level above and are never served.
2. **Every request goes to `public/index.php`.** Real files in `public/` are not served directly, with two exceptions: non-PHP files under `/.well-known/` (ACME validation, `security.txt`) and an explicit `static/` directory if you create one. Any other PHP file must never execute.
3. **Dotfiles are denied** (`403`), except `/.well-known/`.
4. **`enable_post_data_reading = 0`.** Without it PHP consumes form and multipart bodies and uploads are forwarded empty. MirrorGate answers `500` rather than forward an empty body. Set it per site: `.user.ini`, `fastcgi_param PHP_VALUE`, or the pool configuration.
5. **The raw request URI reaches PHP.** MirrorGate validates and forwards `REQUEST_URI` byte for byte. Do not let the server rewrite or decode the path before PHP. With nginx keep `merge_slashes off`. With Apache set `AllowEncodedSlashes NoDecode`, otherwise `%2F` in URLs answers `404` before PHP is reached.
6. **The client TLS state and address are passed on.** `HTTPS` and `REMOTE_ADDR` from the standard FastCGI parameters. The admin endpoint only answers over TLS (or behind a trusted proxy announcing `X-Forwarded-Proto: https`).
7. **No response buffering for streams, and no compression of streams.** MirrorGate sends `X-Accel-Buffering: no` on SSE responses. Disable server-side gzip for the site or for event streams, because MirrorGate compresses buffered responses itself.
8. **Request limits equal to the application limits.** Keep the server's maximum body size at or above `MAX_REQUEST_BODY_BYTES` (default 10 MiB) and its header limits at or above the application's, so error responses come from MirrorGate with the friendly page.

## PHP-FPM

Install PHP 8.3 or newer with `curl`, `mbstring` and `json` (usually part of the base packages), plus `zlib` and `intl`, and preferably `apcu` and `opcache`.

Recommended settings, in `php.ini` or the pool configuration:

```ini
; php.ini
expose_php = Off
memory_limit = 128M
max_execution_time = 60
post_max_size = 12M              ; not smaller than MAX_REQUEST_BODY_BYTES
opcache.enable = 1
opcache.validate_timestamps = 1  ; set 0 only if you reload FPM after every deployment
```

Pool settings (a dedicated pool and Unix user per instance keeps instances separate):

```ini
; /etc/php/8.3/fpm/pool.d/overload.conf
[overload]
user = overload
group = overload
listen = /run/php/overload.sock
listen.owner = www-data
listen.group = www-data
pm = dynamic
pm.max_children = 20
pm.start_servers = 4
pm.min_spare_servers = 2
pm.max_spare_servers = 6
php_admin_value[enable_post_data_reading] = 0
```

`pm.max_children` is the real concurrency limit of the mirror. Keep `GLOBAL_MAX_CONCURRENT` clearly below it so slow origins cannot take every worker. A pool sized for 20 workers pairs well with `GLOBAL_MAX_CONCURRENT=12` to `16`.

Permissions: the project belongs to a deploy user and is only readable by the pool user, except `STORAGE_DIR`, which the pool user must be able to write.

```bash
chown -R deploy:overload /var/www/overload.ir
chmod -R g=rX,o= /var/www/overload.ir
chmod 640 /var/www/overload.ir/.env
install -d -o overload -g overload -m 750 /var/www/overload.ir/var
```

## nginx

`examples/nginx.conf` is a complete configuration for one instance. Summary of what it does:

- A default server block that closes connections (`return 444`) for unknown hosts, so only your host names reach PHP.
- Port 80 serves ACME challenges from `public/` and redirects everything else to HTTPS.
- The HTTPS server uses `public/` as the root, `merge_slashes off`, `gzip off`, `server_tokens off`, `client_max_body_size 10m`.
- Dotfiles are denied; `/.well-known/` stays reachable.
- Only `/index.php` is handed to PHP-FPM, with `fastcgi_param PHP_VALUE "enable_post_data_reading=0"` and `fastcgi_param HTTP_PROXY ""` (httpoxy). Any other `.php` path is internally rewritten to `/index.php` and treated like a mirrored path.
- Everything else is rewritten to `/index.php`. Files in `/.well-known/` and `/static/` are served from disk when they exist.

To install it for another instance, copy the file, change `server_name`, the `root` paths, the certificate paths and the FPM socket, and create a separate `.env` and `STORAGE_DIR`.

For streaming (SSE), MirrorGate sets `X-Accel-Buffering: no` and nginx honours it. To disable FastCGI buffering for the whole instance instead, uncomment `fastcgi_buffering off;` in the `location = /index.php` block.

Check the configuration like this:

```bash
nginx -t && systemctl reload nginx
curl -sI https://overload.ir/.env | head -1          # 403 (denied dotfile)
curl -sI https://overload.ir/ | grep -i x-mirrorgate  # X-MirrorGate-Cache: MISS or HIT
```

### nginx behind a CDN

When Cloudflare, ArvanCloud or another proxy sits in front of nginx, `REMOTE_ADDR` is the proxy's address. Set `TRUSTED_PROXIES` to its ranges (literal CIDRs, or `@file:` pointing at the list the provider publishes) and `TRUSTED_PROXY_HEADER` to the header the proxy uses (`cf-connecting-ip` for Cloudflare). MirrorGate resolves the client address itself; you do not need nginx's `real_ip` module for it. Otherwise all visitors share one rate-limit bucket.

TLS mode: set the CDN to full (strict) TLS. A "flexible" mode that talks plain HTTP to your server causes redirect loops when the server redirects to HTTPS, and it means the admin endpoint sees no TLS.

### Daemon behind nginx

The WebSocket daemon listens on loopback and nginx proxies to it. `WS_PUBLIC_URL` is only a scheme, host and optional port (no path); the path of each WebSocket URL in the mirrored pages is kept. Two ways to expose it:

**On a separate host name (simplest).** Create a host such as `ws.overload.ir` with its own certificate and set `WS_PUBLIC_URL=wss://ws.overload.ir`.

```nginx
server {
    listen 443 ssl;
    listen [::]:443 ssl;
    http2 on;
    server_name ws.overload.ir;

    ssl_certificate     /etc/letsencrypt/live/ws.overload.ir/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/ws.overload.ir/privkey.pem;

    location / {
        proxy_pass http://127.0.0.1:9001;
        proxy_http_version 1.1;
        proxy_set_header Upgrade    $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host       $host;
        proxy_set_header X-Forwarded-For $remote_addr;
        proxy_read_timeout 3600s;
        proxy_send_timeout 3600s;
        proxy_buffering off;
    }
}
```

**On the mirror host itself.** If the origin's WebSocket endpoint has a path that is not also an ordinary page, for example `/socket`, set `WS_PUBLIC_URL=wss://overload.ir` and add a `location ^~ /socket { ... }` block with the same `proxy_*` directives to the existing HTTPS server. This only works when `/socket` is never fetched by normal HTTP requests.

In both cases:

- The daemon accepts only `Host` values of your mirror (`MIRROR_URL`, `MIRROR_ALIASES`) and the host of `WS_PUBLIC_URL`, and only browser `Origin` values from `DAEMON_WS_ORIGIN_ALLOW` (default: your mirror origins).
- To make per-client limits work behind nginx, add `127.0.0.1` to `TRUSTED_PROXIES`. The daemon then reads the client address from the handshake's `X-Forwarded-For`, but only for peers inside that list. For the web application this entry is harmless, since real visitors do not connect from `127.0.0.1`.

More in [daemon.md](daemon.md).

## Apache

`examples/apache-vhost.conf` is a complete Apache 2.4 virtual host that inlines the rules of `public/.htaccess` and sets `AllowOverride None`. Required modules: `rewrite`, `headers`, `setenvif`, `proxy`, `proxy_fcgi`, `ssl`, and optionally `http2`. Highlights:

- `AllowEncodedSlashes NoDecode` so percent-encoded slashes reach MirrorGate.
- `LimitRequestBody`, `LimitRequestFields`, `LimitRequestFieldSize` and `RequestReadTimeout` aligned with the application limits.
- `RequestHeader unset Proxy early` (httpoxy).
- `SetEnvIfNoCase Accept "text/event-stream" no-gzip=1 dont-vary=1` so `mod_deflate` does not buffer event streams.
- `SetHandler "proxy:unix:/run/php/php8.3-fpm.sock|fcgi://localhost/"` for `index.php`, with `ProxySet flushpackets=on` so streamed chunks are passed on immediately, and `ProxyFCGISetEnvIf "true" PHP_VALUE "enable_post_data_reading=0"`.
- `CGIPassAuth On` plus the `E=HTTP_AUTHORIZATION` rewrite rule, so the `Authorization` header reaches PHP.

Enable and test:

```bash
a2enmod rewrite headers setenvif proxy proxy_fcgi ssl http2
apachectl configtest && systemctl reload apache2
```

With `mod_php` instead of PHP-FPM, remove the `SetHandler` block and use `php_value enable_post_data_reading 0`. Prefer PHP-FPM or LiteSpeed; `mod_php` cannot isolate instances from each other.

### WebSocket daemon behind Apache

Enable `proxy_wstunnel` and add a virtual host for the WebSocket host name, mirroring the nginx example:

```apache
<VirtualHost *:443>
    ServerName ws.overload.ir
    SSLEngine on
    SSLCertificateFile    /etc/letsencrypt/live/ws.overload.ir/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/ws.overload.ir/privkey.pem

    ProxyPreserveHost On
    ProxyPass        "/" "ws://127.0.0.1:9001/"
    ProxyPassReverse "/" "ws://127.0.0.1:9001/"
    ProxyTimeout 3600
</VirtualHost>
```

with `WS_PUBLIC_URL=wss://ws.overload.ir` and `127.0.0.1` in `TRUSTED_PROXIES`. The daemon takes the client address from the `X-Forwarded-For` header of the handshake, so confirm that your Apache build passes it on to the WebSocket backend; otherwise every client appears as `127.0.0.1` and shares the per-IP connection limit. This snippet is not exercised by the repository's tests: test it with a real WebSocket client and the daemon log.

## FrankenPHP (optional)

FrankenPHP (Caddy with an embedded PHP) can serve MirrorGate in its classic, non-worker mode. The repository ships no FrankenPHP configuration and its automated tests do not run under FrankenPHP, so treat the following as a starting point and verify it with the checks below before going live.

Requirements for any Caddy or FrankenPHP setup:

- Document root `public/`; every request must end up in `index.php`; no static file from `public/` is served except under `.well-known/` and `static/`.
- Dotfiles answer `403` except `/.well-known/`.
- `enable_post_data_reading` must be `0` for the PHP runtime. FrankenPHP reads ini settings from its global option block (`php_ini`) or from a `php.ini`; check the documentation of your FrankenPHP version.
- Do not use worker mode. MirrorGate is written for one request per script execution, and it registers shutdown functions and per-request state.
- `REQUEST_URI` must be the original request URI.

A sketch of a Caddyfile for one instance (validate it with `caddy validate`; directive names and ordering can differ between versions):

```caddyfile
{
    frankenphp {
        php_ini enable_post_data_reading 0
    }
}

overload.ir, www.overload.ir {
    root * /var/www/overload.ir/public

    @dotfiles path_regexp dot (^|/)\.(?!well-known(/|$))
    handle @dotfiles {
        respond 403
    }

    # Everything else is handled by MirrorGate.
    handle {
        rewrite * /index.php
        php_server {
            file_server off
        }
    }
}
```

Caddy obtains and renews certificates by itself. The dotfile rule is in its own `handle` block on purpose: if the `rewrite` ran first, the path would no longer match the dotfile pattern.

After starting it, verify the points that matter:

```bash
# Dotfiles are denied.
curl -sI https://overload.ir/.env | head -1

# An encoded slash must reach MirrorGate and the origin unchanged,
# not be answered with 400 or 404 by the web server.
curl -sI 'https://overload.ir/a%2Fb?x=1' | head -1

# A form upload must arrive at the origin with its body. Then confirm through the
# admin POST /check endpoint that ini.enable_post_data_reading is "ok".
curl -s -o /dev/null -w '%{http_code}\n' -F f=@/etc/hostname https://overload.ir/
```

If any probe fails, use nginx or Apache, which are covered by `examples/`.

## Running the daemon

See [daemon.md](daemon.md) for listener syntax and limits. Typical VPS setup with systemd, one unit per instance:

```bash
cp /var/www/overload.ir/examples/mirrorgate-daemon.service \
   /etc/systemd/system/mirrorgate-daemon-overload.service
# edit User, WorkingDirectory, ExecStart/ExecStop (--env=...), ReadWritePaths
systemctl daemon-reload
systemctl enable --now mirrorgate-daemon-overload
journalctl -u mirrorgate-daemon-overload -f
```

## Deployment and updates

MirrorGate has no build step and no Composer runtime dependencies.

```bash
# first deployment
rsync -a --delete --exclude '/.env' --exclude '/var' --exclude '/tests' --exclude '/docs' \
      ./ deploy@server:/var/www/overload.ir/

# on the server
php /var/www/overload.ir/bin/mirrorgate check --network
systemctl reload php8.3-fpm            # clears opcache if validate_timestamps is 0
systemctl restart mirrorgate-daemon-overload   # only if you run the daemon
```

Do not delete `.env` or `var/` with `--delete`; the exclusions above protect them. A changed release or a changed rewrite configuration automatically stops older cache entries from being served (see [caching.md](caching.md#cache-keys-and-fingerprints)).

For cache garbage collection, add a cron job (this line is for a file in `/etc/cron.d/`, where the sixth field is the user; in a personal crontab omit it) or a systemd timer:

```
*/10 * * * * overload php /var/www/overload.ir/bin/mirrorgate-gc --quiet
```

The tool reads the `.env` in the project root; for an environment file elsewhere, set `MIRRORGATE_ENV` in the job.

## Firewall and network

- Allow inbound 80 and 443 to the web server only.
- Daemon listeners bind to `127.0.0.1` by default. If you expose one, bind it to a specific address with `DAEMON_ALLOW_PUBLIC_BIND=true` and restrict clients with the firewall, `DAEMON_TCP_CLIENT_ALLOW` or `DAEMON_UDP_CLIENT_ALLOW`.
- Outbound: the server needs HTTPS (and DNS) access to the origin. If outbound traffic is filtered, set `OUTBOUND_PROXY` (see [troubleshooting.md](troubleshooting.md#the-origin-is-unreachable-from-the-host)).
- Do not run the PHP pool as root or as a user that can write the code directory.

## Log rotation

MirrorGate rotates its own log once (`<log>.1`) when it exceeds `LOG_MAX_BYTES` (5M by default), so the data directory cannot grow without bound. System-level rotation of nginx and Apache logs is separate and should be configured as usual. The daemon writes to `STORAGE_DIR/logs/daemon.log` (and to standard error in `--foreground` mode, which systemd captures in the journal).
