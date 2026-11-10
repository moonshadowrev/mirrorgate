# The daemon: WebSocket, gRPC, TCP and UDP

PHP running under a web server (php-fpm, `mod_php`, CGI, LiteSpeed) answers one HTTP request per script execution. It cannot hold a WebSocket, an HTTP/2 stream for gRPC, a raw TCP connection or a UDP socket open for minutes or hours. Shared hosting adds hard process-time limits and usually allows only ports 80 and 443 inbound.

`bin/mirrorgate-daemon` is an optional, separate, long-running command-line process for those protocols. It is a plain PHP program with its own `stream_select()` event loop. It does not need Composer, extensions beyond the usual ones (`pcntl`, `posix`, `sockets` and `openssl` are used when present), or a web server. It reads the same `.env` as the web application.

If you only mirror ordinary websites, you do not need the daemon.

## What it can and cannot do

| Protocol | Behaviour |
|---|---|
| WebSocket | Accepts the HTTP/1.1 upgrade, validates it, opens a connection to the origin, repeats the upgrade there, and then relays bytes in both directions. WebSocket frames are never parsed |
| gRPC | An opaque byte pipe for HTTP/2, either cleartext (`h2c`) or TLS with ALPN `h2` |
| TCP | A raw byte pipe to a TCP or TLS target |
| UDP | A datagram forwarder with one upstream socket per client address and port |

Honest limits:

- **One process, one thread.** `stream_select()` cannot watch descriptors numbered 1024 or higher. Each forwarded connection uses two descriptors, so the daemon accepts at most about 494 simultaneous forwarded connections, whatever `DAEMON_MAX_CONNECTIONS` says. `bin/mirrorgate-daemon check` prints the exact number. For more, run several daemons on different ports.
- **No protocol awareness above the transport.** For gRPC the HTTP/2 frames are never parsed: no per-RPC routing, no header or path rewriting and no host validation. Everything that connects is relayed to the single configured target. The same holds for WebSocket frames after the handshake and for TCP and UDP payloads.
- **No hot reload.** Configuration, listener definitions and TLS certificates are read at start. After a change, restart the daemon. `SIGHUP` only writes a log line.
- **DNS lookups block the loop.** PHP has no asynchronous resolver. Targets are resolved before the loop starts and then refreshed by a timer, at most one lookup every five seconds; a slow or failing resolver can stall all connections for the duration of that lookup. Failing hosts are backed off.
- **Listener TLS is static and unauthenticated.** With `tls_cert` and `tls_key` the daemon terminates TLS using the files you give it. Clients are not authenticated (no client certificates).
- **No HTTP in front.** A WebSocket client must reach the daemon directly or through a reverse proxy that passes the upgrade. Plain HTTP requests to a `ws` listener are rejected.
- **Not for shared hosting.** Shared hosting normally forbids long-running processes and closes non-web ports. Run the daemon on a small VPS, and point a host name such as `ws.ormantek.com` at it (see [WebSocket URLs](#websocket-urls)). The daemon does not need access to the PHP mirror's files, only to a `.env` with `ORIGIN_URL`, `MIRROR_URL` and the `DAEMON_*` variables.
- **PHP memory.** A long-running PHP process can grow. The daemon checks its memory every five seconds and, above 90 percent of `DAEMON_MEMORY_LIMIT`, drains and exits with code 3 so the supervisor restarts it.

## Commands

```
bin/mirrorgate-daemon start|stop|status|check [--env=PATH] [--foreground]
```

| Command | What it does |
|---|---|
| `check` | Validates every listener, resolves the targets, probes whether the bind addresses are free and prints capability warnings. Exit status 1 if anything fails. Nothing is started |
| `start` | Starts the daemon. With `ext-pcntl` and `ext-posix` it forks into the background and reports the PID; without them it prints a message and runs in the foreground. `--foreground` never forks and also logs to standard error (for systemd, supervisord and Docker) |
| `status` | Prints `running (pid N)` and exits 0, or prints `not running` and exits 3 |
| `stop` | Writes the stop file, sends `SIGTERM` when `ext-posix` exists, and waits for the daemon to exit |

Exit codes of the daemon process: `0` clean stop, `1` configuration or startup failure, `2` fatal runtime error, `3` restart for memory. A second daemon with the same PID file refuses to start (the PID file is locked with `flock`).

On shutdown the daemon stops accepting, lets open connections finish for up to 10 seconds, then closes them. The stop file (`DAEMON_STOP_FILE`) is polled four times a second, so stopping works without `pcntl`.

The daemon logs to `DAEMON_LOG_FILE` (default `STORAGE_DIR/logs/daemon.log`) at the configured `LOG_LEVEL`. Log lines never contain payloads, cookies or tokens. The files are opened on every write; the file is rotated once when it exceeds `LOG_MAX_BYTES`.

## Listener syntax

Listeners are defined one per variable, `DAEMON_LISTEN_1` to `DAEMON_LISTEN_32` (numbers need not be contiguous):

```
DAEMON_LISTEN_n=<kind>://<bind-ip>:<bind-port>-><target-scheme>://<target-host>:<target-port>[?option=value&option=value]
```

| Kind | Allowed target schemes | Meaning |
|---|---|---|
| `ws` | `ws`, `wss` | WebSocket upgrade, then an opaque pipe |
| `tcp` | `tcp`, `tls` | Raw TCP. `tls` makes the daemon a TLS client toward the target |
| `udp` | `udp` | Datagram forwarding with sessions |
| `grpc` | `h2c`, `tls` | HTTP/2 pipe, cleartext on the listener side unless `tls_cert` is given; `tls` target uses ALPN `h2` |

Rules, checked at start and by `check`:

- The bind host must be an IP literal (`127.0.0.1`, `[::1]`, `0.0.0.0`). Binding anything other than `127.0.0.0/8` or `::1` requires `DAEMON_ALLOW_PUBLIC_BIND=true`.
- Ports are 1 to 65535. Ports below 1024 produce a warning (they need elevated privileges).
- The target host must be listed in `DAEMON_TARGET_ALLOWLIST`, which defaults to the origin host plus `EXTRA_ORIGINS`. Anything else is refused when the configuration is read. There is no way to make the daemon connect to a host that is not in the list.
- After DNS resolution every address must be globally routable, unless `DAEMON_ALLOW_PRIVATE_TARGETS=true`. If any answer for a host is private, the whole host is refused. Link-local and cloud-metadata addresses are never allowed.
- Two listeners cannot share a bind address and port (TCP and UDP are separate namespaces).
- The definition may not contain whitespace. Unknown options and options given twice are errors.

Examples:

```ini
# WebSocket: wss://ws.ormantek.com -> (reverse proxy) -> 127.0.0.1:9001 -> wss://overlord.team
DAEMON_LISTEN_1=ws://127.0.0.1:9001->wss://overlord.team:443

# Raw TCP with a TLS target
DAEMON_LISTEN_2=tcp://127.0.0.1:9002->tls://overlord.team:443

# UDP, for example DNS
DAEMON_LISTEN_3=udp://127.0.0.1:9003->udp://overlord.team:53

# gRPC with a TLS target
DAEMON_LISTEN_4=grpc://127.0.0.1:9004->tls://overlord.team:443
```

### Options

Options go after `?` and are separated by `&`. Values are URL-decoded.

| Option | Applies to | Meaning |
|---|---|---|
| `idle` | all | Idle timeout in seconds, 1 to 86400. Default `DAEMON_IDLE_TIMEOUT` (UDP: `DAEMON_UDP_SESSION_TTL`) |
| `max_conn` | all | Connection limit of this listener, 1 to 1000 |
| `max_per_ip` | all | Connections per client address on this listener, 1 to 1000 |
| `tls_cert`, `tls_key` | `ws`, `tcp`, `grpc` | PEM files for TLS termination on the listener. Both or neither. Not available for `udp` |
| `alpn` | with `tls_cert`, or a TLS target | Comma separated protocol names (1 to 8). gRPC listeners default to `h2` |
| `sni` | TLS targets | Name used for SNI and certificate verification instead of the target host |
| `host` | `ws` | `Host` header sent to the origin (a host name, optional port). Default: the target host, with the port when it is not 80 or 443 |
| `origin_allow` | `ws` | Comma separated exact browser origins (`https://ormantek.com`) accepted on this listener. Overrides `DAEMON_WS_ORIGIN_ALLOW` |
| `allow_no_origin` | `ws` | `1` to accept handshakes without an `Origin` header. Overrides `DAEMON_WS_ALLOW_NO_ORIGIN` |
| `proxy_protocol` | `tcp`, `grpc` | `1` to expect a PROXY protocol v1 header from a trusted proxy |

Example with options:

```ini
DAEMON_LISTEN_1=ws://127.0.0.1:9001->wss://overlord.team:443?origin_allow=https://ormantek.com,https://www.ormantek.com&idle=300
DAEMON_LISTEN_4=grpc://0.0.0.0:8443->tls://overlord.team:443?tls_cert=/etc/ssl/mg/fullchain.pem&tls_key=/etc/ssl/mg/privkey.pem
```

## Per-protocol behaviour

### WebSocket

The `ws` listener implements the handshake itself. For every connection:

1. The request must be `GET <path> HTTP/1.1` with `Upgrade: websocket`, a `Connection` header containing `upgrade`, a valid `Sec-WebSocket-Key` (16 bytes, base64) and `Sec-WebSocket-Version: 13`. Bare line feeds, NUL bytes, obsolete line folding, duplicate `Host`, `Origin`, key, version and `Upgrade` headers, absolute-form targets, backslashes and a body (`Content-Length` or `Transfer-Encoding`) on the handshake are refused with `400`. Handshakes larger than `DAEMON_MAX_HEADER_BYTES` are refused with `431`, and clients that do not finish within `DAEMON_HANDSHAKE_TIMEOUT` are dropped.
2. The `Host` header must be one of your mirror hosts (`MIRROR_URL`, `MIRROR_ALIASES`) or the host of `WS_PUBLIC_URL`; otherwise `421`.
3. The browser `Origin` must be in the allow list (`403` otherwise). By default the allow list is the origin of `MIRROR_URL` plus the origin of every alias. A missing `Origin` is refused unless `allow_no_origin` or `DAEMON_WS_ALLOW_NO_ORIGIN` is on, which is meant for non-browser clients.
4. The request path is normalised and checked against `BLOCKED_PATHS`, `AUTH_PASSTHROUGH_BLOCK` and the admin prefix; blocked paths get `404`.
5. The client address is the TCP peer, unless the peer is inside `TRUSTED_PROXIES`, in which case it is derived from the configured forwarding header (rightmost untrusted entry for `X-Forwarded-For`). Per-client limits use this address (IPv6 per /64).
6. The daemon connects to the target (an address that passed the SSRF checks) and sends its own upgrade request: `Host` is the target host (or the `host` option), `Origin` is the origin URL from `ORIGIN_URL` (only if the client sent one), `Sec-WebSocket-Protocol` and `-Extensions` are forwarded as offered, `Cookie` is forwarded without cookies named `mg_*` (when `FORWARD_COOKIES` is on), `Authorization` is forwarded when `FORWARD_AUTHORIZATION` is on, and `User-Agent` and `Accept-Language` are passed along. `X-Forwarded-For` is set to exactly one address, never appended to a client value.
7. The origin must answer `101` with a valid `Sec-WebSocket-Accept`; anything else becomes `502` for the client. The `101` response sent to the client contains only the headers the protocol needs (`Upgrade`, `Connection`, `Sec-WebSocket-Accept` and a subprotocol or extension the origin chose, if valid). Origin cookies and other headers are not relayed.
8. After the `101`, bytes are piped in both directions with back-pressure.

Handshake failures are answered with a minimal `HTTP/1.1 <status>` and no body. Reasons are logged at `debug` level.

### gRPC

A `grpc` listener is a TCP pipe tuned for HTTP/2. The listener speaks prior-knowledge `h2c`, or TLS with ALPN `h2` when `tls_cert` and `tls_key` are set. For a `tls` target the daemon connects with ALPN `h2` and verifies the certificate against `sni` or the target host (and `CA_BUNDLE` if set). The origin connection is opened only after the client has sent its first bytes, so idle connections cost nothing at the origin. HTTP/1.1 `Upgrade: h2c` is not honoured.

Long-lived streams stay open as long as frames (including PING) flow; the idle timeout closes silent connections. Browsers cannot speak gRPC directly. gRPC-Web clients use ordinary HTTP, which goes through the PHP mirror: the requests are forwarded like any other POST and the bodies are not rewritten (their content types are not on the rewrite list). That path is not covered by this project's tests, and long-lived streaming responses are limited by PHP's request limits, see [configuration.md](configuration.md#streaming-limits).

If the origin's gRPC server has a certificate for its own name only, clients that connect to the daemon's TLS listener must trust the certificate you provide with `tls_cert`. The daemon terminates TLS with that certificate and opens a separate TLS connection to the origin.

### TCP

A `tcp` listener pipes bytes. With a `tls` target the daemon performs the TLS handshake with the origin (with certificate verification that cannot be disabled), and the origin connection is opened only after the client has sent its first bytes. With a `tcp` target the connection is made at once, because many protocols let the server speak first. `DAEMON_TCP_CLIENT_ALLOW` restricts which clients may use TCP and gRPC listeners. `proxy_protocol=1` makes the listener read a PROXY protocol v1 header (`PROXY TCP4 ...`) and trust the address in it, but only from peers inside `TRUSTED_PROXIES`.

### UDP

A `udp` listener creates one upstream socket per client address and port and relays replies back. Because UDP source addresses can be spoofed, a UDP listener is a potential reflection and amplification tool, so extra rules apply:

- A listener on a non-loopback address requires a non-empty `DAEMON_UDP_CLIENT_ALLOW`. Ranges wider than `/8` (IPv4) or `/32` (IPv6), including `0.0.0.0/0`, are rejected at configuration time. Datagrams from other sources are dropped.
- Per client address, the bytes sent back may not exceed `DAEMON_UDP_MAX_AMPLIFICATION` (default 2) times the bytes received plus a one-time allowance of 4 KiB. Excess replies are dropped.
- Each client address may send at most `DAEMON_UDP_RATE_PER_SEC` datagrams per second (default 200). Empty datagrams are ignored.
- Sessions expire after `DAEMON_UDP_SESSION_TTL` seconds of inactivity. The number of sessions is capped by `DAEMON_UDP_MAX_SESSIONS` and by the listener's `max_conn` and `max_per_ip`.
- A datagram larger than `DAEMON_UDP_MAX_DATAGRAM` is dropped.

## WebSocket URLs

For a mirrored page that opens `new WebSocket("wss://overlord.team/socket")`, three things have to line up.

1. **The daemon must be reachable by browsers** at a public `wss://` address, usually through a reverse proxy that terminates TLS (see [deployment-vps.md](deployment-vps.md#daemon-behind-nginx)), or directly with `tls_cert` and `tls_key`.
2. **Set `WS_PUBLIC_URL`** in the `.env` of the web instance to the scheme, host and optional port of that address, for example:

   ```ini
   WS_PUBLIC_URL=wss://ws.ormantek.com
   ```

   No path is allowed in the value. MirrorGate then rewrites `ws(s)://overlord.team/...` literals in HTML, JSON and (with `REWRITE_JS=true`) JavaScript to `wss://ws.ormantek.com/...`, keeping the path. Without `WS_PUBLIC_URL` such literals are left untouched, and the browser keeps connecting to the origin directly. That works for sockets that accept foreign origins, and does not go through the mirror.
3. **Browser `Origin` must be accepted.** A page served from `https://ormantek.com` sends `Origin: https://ormantek.com`, which the default `DAEMON_WS_ORIGIN_ALLOW` covers. If you serve the page from other hosts, add them to `DAEMON_WS_ORIGIN_ALLOW`.

Scripts that build WebSocket URLs at runtime from `location.host`, or from a configuration value that is not a literal host name, are not affected by rewriting. A script that uses `new WebSocket("wss://" + location.host + "/socket")` connects to the mirror host, so route that path in your reverse proxy to the daemon. If an application only has relative URLs, no rewriting is needed.

The WebSocket upgrade also fails on the PHP side: a normal HTTP request that carries `Upgrade: websocket` and reaches PHP is answered with `501`.

## Keeping the daemon running

### systemd (recommended)

`examples/mirrorgate-daemon.service` is a hardened unit. Copy it per instance, then adjust the user, paths and `--env`:

```bash
cp examples/mirrorgate-daemon.service /etc/systemd/system/mirrorgate-daemon-ormantek.service
systemctl daemon-reload
systemctl enable --now mirrorgate-daemon-ormantek
journalctl -u mirrorgate-daemon-ormantek -f
```

Points to know about that unit:

- `ExecStart` uses `start --foreground`, so systemd supervises the process and the log also goes to the journal. `ExecStop` calls `stop`.
- `Restart=always` brings the daemon back after exit code `3` (memory) or `2` (fatal). `RestartPreventExitStatus=1` stops restart loops caused by configuration errors: fix the configuration instead.
- `LimitNOFILE=4096` lets the process open the descriptors it needs (more than 1024 is never useful because of `select()`).
- `TimeoutStopSec=20` leaves room for the 10 second drain.
- Give every instance a different `DAEMON_PID_FILE`, and different listener ports.
- To bind ports below 1024, uncomment `AmbientCapabilities=CAP_NET_BIND_SERVICE` and `CapabilityBoundingSet=CAP_NET_BIND_SERVICE`, or prefer high ports behind a reverse proxy.

### supervisord

```ini
[program:mirrorgate-daemon-ormantek]
command=/usr/bin/php /opt/mirrorgate/bin/mirrorgate-daemon start --foreground --env=/opt/mirrorgate/instances/ormantek/.env
directory=/opt/mirrorgate
user=mirrorgate
autostart=true
; restart on exit codes 2 and 3, but not after a clean stop (0) or a configuration error (1)
autorestart=unexpected
exitcodes=0,1
startsecs=5
startretries=3
stopsignal=TERM
stopwaitsecs=20
redirect_stderr=true
stdout_logfile=/var/log/mirrorgate/overload-daemon.out.log
```

### Without root: nohup and a cron watchdog

If you only have a user account on a server that allows long-running processes, a cron job can start the daemon when it is not running:

```
* * * * * /usr/bin/php /home/user/mirrorgate/bin/mirrorgate-daemon status --env=/home/user/instances/ormantek/.env >/dev/null 2>&1 || nohup /usr/bin/php /home/user/mirrorgate/bin/mirrorgate-daemon start --env=/home/user/instances/ormantek/.env >/dev/null 2>&1 &
```

`status` exits 0 only while the daemon runs. Without `ext-pcntl` the `start` command stays in the foreground, which `nohup ... &` handles. A screen or tmux session also works for testing. These methods have no automatic memory restart supervision beyond the cron watchdog; check the log regularly.

## Security checklist

- Keep listeners on loopback and put a TLS-terminating reverse proxy in front, or provide `tls_cert` and `tls_key`.
- For any other bind address set `DAEMON_ALLOW_PUBLIC_BIND=true` knowingly and restrict clients with a firewall, `DAEMON_TCP_CLIENT_ALLOW` or `DAEMON_UDP_CLIENT_ALLOW`. `check` warns about public listeners without TLS.
- Keep `DAEMON_TARGET_ALLOWLIST` as small as possible. A listener can only ever reach hosts in that list.
- Leave `DAEMON_ALLOW_PRIVATE_TARGETS` off unless the origin is on a private network.
- Do not set `DAEMON_WS_ALLOW_NO_ORIGIN` or `allow_no_origin=1` unless you need non-browser clients; it is meant as an opt-in, and `check` warns about it.
- Set `TRUSTED_PROXIES` only to proxies you run; otherwise clients behind your reverse proxy all look like one address and share the per-IP limits.
- Size `DAEMON_MAX_CONNECTIONS`, `DAEMON_BUFFER_HIGH_WATER` and `DAEMON_MEMORY_LIMIT` together. `bin/mirrorgate check` warns (`daemon.memory_budget`) when the worst case (`DAEMON_MAX_CONNECTIONS` x 2 x (`DAEMON_BUFFER_HIGH_WATER` + 64K)) exceeds the memory limit.

## Other engines

Workerman, ReactPHP, Swoole or RoadRunner can hold sockets with more concurrency than `stream_select()` allows, and are an option for very busy WebSocket mirrors. They are not integrated with MirrorGate and are not covered by its tests; this project provides no configuration for them. You can still use MirrorGate's web application unchanged and put such a server behind the same reverse proxy path.

## Testing the daemon

The repository contains a self-test that starts the daemon against local echo servers and checks handshake validation, framing relay, back-pressure, limits and shutdown:

```bash
php tools/daemon-selftest.php
```
