# Security Policy

MirrorGate is a reverse proxy that sits in front of third-party origins, so
security reports are taken seriously and handled with priority.

## Supported versions

| Version | Supported |
|---------|-----------|
| 0.1.x   | Yes       |

Only the latest release line receives security fixes while the project is
pre-1.0.

## Reporting a vulnerability

Please do not open a public issue, pull request, or discussion for a
vulnerability.

Report it privately through a GitHub security advisory:
<https://github.com/moonshadowrev/mirrorgate/security/advisories/new>

Include, where possible:

- The affected version and PHP version / SAPI.
- A description of the issue and its impact.
- Minimal reproduction steps or a proof of concept. Redact real hostnames,
  credentials, and `.env` values.
- Any suggested mitigation.

You can expect an acknowledgement within a few days. Fixes are developed in a
private advisory, released, and then disclosed with credit to the reporter
unless you ask to remain anonymous. Please allow a reasonable period for a fix
before any public disclosure.

## Scope

In scope:

- SSRF, including DNS rebinding and redirects to internal addresses
- Header and CRLF injection, request smuggling
- Cache poisoning and path traversal in cache keys or files
- Open redirects and host header attacks
- XSS introduced by content rewriting
- Admin endpoint authentication bypass
- Leakage of `.env` values or origin internals
- Denial of service through missing limits (body size, redirects, timeouts,
  rate limiting)

Out of scope:

- Vulnerabilities in the origin site being mirrored
- Issues requiring control of the server's filesystem or `.env` file
- Findings that depend on deliberately insecure configuration (for example,
  disabling the SSRF protections or using a weak admin token)
- Denial of service through raw volumetric traffic

## Deployment hardening

- Keep `.env` outside the web root where the host allows it, or deny web access
  to it. Never commit it.
- Use a long random admin token and serve the admin endpoint over HTTPS only.
- Keep the origin allow-list as narrow as possible.
- Run the daemon as an unprivileged user and bind it to the narrowest interface
  that works.
- Keep PHP and its extensions up to date.
