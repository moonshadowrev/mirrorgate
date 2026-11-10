# Getting started

MirrorGate is a PHP application that mirrors a remote website on your own domain. You point a domain you control (the **mirror**, for example `overload.ir`) at MirrorGate, give it the address of the site you want to mirror (the **origin**, for example `overlord.team`), and it behaves like a small CDN or reverse proxy:

- It forwards every request to the origin and streams the answer back.
- It rewrites the origin host name to the mirror host name in HTML, CSS, JSON, XML, redirects, cookies and selected headers, so links stay on the mirror.
- It caches responses on disk and serves stale copies when the origin fails.
- It replaces origin errors, timeouts, DNS and TLS failures with a friendly error page.
- It streams Server-Sent Events.

Everything is configured with one `.env` file. There are no Composer packages to install at runtime.

WebSocket, gRPC, raw TCP and UDP cannot be handled by PHP running under a web server. For those there is an optional long-running command-line daemon, described in [daemon.md](daemon.md).

## Requirements

| Requirement | Detail |
|---|---|
| PHP | 8.3 or newer. The test suite passes on 8.3, 8.4 and 8.5. |
| Required extensions | `curl`, `json`, `mbstring` |
| Optional extensions | `zlib` (gzip decoding, recompression, compressed cache bodies), `apcu` (faster rate limiting), `intl` (native IDN conversion; a pure PHP fallback exists), `brotli` (decode Brotli origin responses), `pcntl` and `posix` (daemon only) |
| Web server | Apache, LiteSpeed, nginx with PHP-FPM, IIS with FastCGI, or `php -S` for local trials |
| Writable disk | A directory outside the web root for cache, logs and state (`STORAGE_DIR`) |
| Outbound network | The PHP process must be able to open HTTPS connections to the origin |

Composer is not needed to run MirrorGate. `src/autoload.php` is a small PSR-4 autoloader and `vendor/` is never loaded at runtime. Composer is only used by developers for PHPUnit and PHPStan.

## How a request flows

1. The web server hands every request to `public/index.php`.
2. MirrorGate validates the request, checks that the `Host` header is one of your mirror hosts, applies rate limits and looks in the cache.
3. On a cache miss it sends an upstream request to the origin. The upstream connection is always built from your configuration; nothing from the client request can change the destination.
4. The response is rewritten (mirror host instead of origin host), stored in the cache when allowed, and sent to the visitor.

[architecture.md](architecture.md) describes this in detail.

## Try it locally in two minutes

The repository contains a small demo origin that exercises links, cookies, redirects, SSE and errors. You only need PHP 8.3+ with `curl`, `json` and `mbstring`.

From the project root, start the demo origin:

```bash
php -S 127.0.0.1:9101 examples/demo-origin/router.php
```

In a second terminal create a throwaway environment file and start MirrorGate with PHP's built-in server:

```bash
cat > /tmp/mirrorgate-trial.env <<'EOF'
ORIGIN_URL=http://127.0.0.1:9101
MIRROR_URL=http://localhost:8080
ORIGIN_ALLOW_HTTP=true
ALLOW_PRIVATE_ORIGIN=true
EOF

MIRRORGATE_ENV=/tmp/mirrorgate-trial.env php -S 127.0.0.1:8080 -t public public/index.php
```

The two extra flags are only for this demo: the demo origin uses plain `http` and listens on a loopback address, which MirrorGate refuses by default (see [security.md](security.md)). Never set them for a real origin.

In a third terminal:

```bash
curl -i http://localhost:8080/
```

The links in the returned HTML point at `http://localhost:8080/`, not at `127.0.0.1:9101`. The response carries `X-MirrorGate-Cache: MISS`; repeat the request and it becomes `HIT`.

Check the setup with the built-in self check:

```bash
php bin/mirrorgate check --env=/tmp/mirrorgate-trial.env
```

The output has one line per check, tagged `OK`, `WARN` or `FAIL`. The command exits with status 1 when any check fails. In this trial it prints warnings about the private origin, the plain HTTP origin, and `enable_post_data_reading` (a command-line PHP shows its own value; see [troubleshooting.md](troubleshooting.md)). Those are expected here.

The repository also ships an end-to-end script that starts the demo origin and a mirror on random local ports and asserts the behaviour with `curl`:

```bash
bash scripts/e2e.sh
```

and a dependency-free unit test runner:

```bash
php tests/run.php
```

## Your first real mirror

### 1. Prepare the files

Upload the release to your server (see [deployment-shared-hosting.md](deployment-shared-hosting.md) for cPanel and DirectAdmin, or [deployment-vps.md](deployment-vps.md) for a server you control). The directory layout that matters is:

```
mirrorgate/
  .env                 your configuration (never inside the web root)
  public/              the only directory the web server should serve
    index.php          front controller
    .htaccess          Apache and LiteSpeed rules
    .user.ini          PHP-FPM / CGI setting enable_post_data_reading = 0
  src/  bin/  templates/  var/ (created at runtime)
```

### 2. Write the `.env`

Copy `.env.example` to `.env`. Only two variables are required:

```ini
ORIGIN_URL=https://overlord.team
MIRROR_URL=https://overload.ir
```

Both are `scheme://host[:port]` with no path. `MIRROR_URL` must be a different host from the origin and must not be a subdomain of it. Every other variable is optional and documented in [configuration.md](configuration.md).

Sensible additions for a public mirror:

```ini
# Count the access log lines (the default log level only records warnings and errors)
LOG_LEVEL=info

# Also accept the www host
MIRROR_ALIASES=www.overload.ir

# Never forward login or OAuth endpoints through the mirror
AUTH_PASSTHROUGH_BLOCK=/login*,/oauth/*,/api/auth/*,/signin*,/account/*
```

### 3. Point the web server at `public/`

Set the document root of the mirror domain to `mirrorgate/public`. If your hosting plan does not allow that, [deployment-shared-hosting.md](deployment-shared-hosting.md) describes the alternatives (a tiny stub in the existing document root, or the root shim).

### 4. Validate

If you have shell access:

```bash
php bin/mirrorgate check
php bin/mirrorgate check --network
```

`--network` additionally probes whether `/.env`, `/.env.php` and `/src/autoload.php` are publicly readable on the mirror, and tests DNS, TCP and TLS reachability of the origin.

Without shell access, enable the admin endpoint temporarily and call `POST /__mirrorgate/check` (see [security.md](security.md#admin-endpoint)).

Then open `https://overload.ir/` in a browser. Fix every `FAIL` before going live and read every `WARN`.

## What MirrorGate does not do

- It does not make an authenticated application safe to mirror. Everything that passes through, including cookies and tokens, is visible to the mirror operator. Read [security.md](security.md) before mirroring anything with logins.
- It cannot proxy WebSocket, gRPC, raw TCP or UDP from inside PHP. A request with an `Upgrade` header other than `h2c` is answered with `501`. Use the [daemon](daemon.md).
- It does not mirror wildcard domains. One instance serves one mirror host (plus aliases). Many hosts are handled by running many instances, see [multi-instance.md](multi-instance.md).
- It does not bypass anything on the origin side. If the origin or its CDN blocks the IP address of your hosting provider, the mirror will receive those blocks. See [troubleshooting.md](troubleshooting.md).

## Where to go next

| Topic | Document |
|---|---|
| Every `.env` variable | [configuration.md](configuration.md) |
| cPanel and DirectAdmin, upload-only install | [deployment-shared-hosting.md](deployment-shared-hosting.md) |
| Several domains or subdomains on one account | [multi-instance.md](multi-instance.md) |
| nginx, Apache, FrankenPHP on a VPS | [deployment-vps.md](deployment-vps.md) |
| WebSocket, gRPC, TCP, UDP | [daemon.md](daemon.md) |
| Cache behaviour and purging | [caching.md](caching.md) |
| What is rewritten and what is not | [rewriting.md](rewriting.md) |
| Threat model and hardening | [security.md](security.md) |
| Something does not work | [troubleshooting.md](troubleshooting.md) |
| Internals | [architecture.md](architecture.md) |
