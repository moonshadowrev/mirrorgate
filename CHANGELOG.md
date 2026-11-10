# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-11-10

Initial release.

### Added

- Configuration-only reverse-proxy mirror: deploy on domain B to mirror origin A,
  configured entirely through `.env`.
- Request proxying with rewriting of the origin host to the mirror host in
  HTML, CSS, JavaScript, JSON, XML, response headers, cookies, and redirects.
- Response cache with stale-if-error support.
- Friendly error pages for origin timeouts, 5xx responses, DNS failures, and
  TLS errors.
- Server-Sent Events streaming.
- Optional long-running CLI daemon (`bin/mirrorgate-daemon`) for WebSocket,
  gRPC (h2c / L4 passthrough), raw TCP, and UDP forwarding.
- Support for multiple independent instances, one per domain or subdomain, each
  with its own document root and `.env`.
- Security controls: SSRF protection, header/CRLF injection defenses, request
  smuggling mitigations, cache key and path traversal protection, open redirect
  and host header protection, constant-time admin authentication, and limits
  for body size, redirects, timeouts, and request rate.
- Zero required Composer runtime dependencies: built-in PSR-4 autoloader and
  `.env` parser. Requires PHP 8.3+ with `ext-curl`, `ext-json`, `ext-mbstring`;
  `ext-zlib` and Brotli are optional.
- Apache-2.0 license and community health files.

[Unreleased]: https://github.com/moonshadowrev/mirrorgate/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/moonshadowrev/mirrorgate/releases/tag/v0.1.0
