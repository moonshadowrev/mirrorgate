# Contributing to MirrorGate

Thanks for your interest in improving MirrorGate. This guide explains how to
set up, what the project expects, and how to submit changes.

By participating you agree to follow the [Code of Conduct](CODE_OF_CONDUCT.md).
Report vulnerabilities privately as described in [SECURITY.md](SECURITY.md).

## Project constraints

Please keep these in mind; changes that break them will not be accepted:

- **PHP 8.3+**, and clean on PHP 8.4 and 8.5: no deprecated APIs, no dynamic
  properties, explicit nullable types.
- **Zero required Composer runtime dependencies.** The project ships its own
  PSR-4 autoloader (`src/autoload.php`) and `.env` parser. Only `ext-curl`,
  `ext-json`, and `ext-mbstring` are required; `ext-zlib` and Brotli are
  optional.
- **Upload-only shared hosting friendly.** The web app must work without shell
  access, a build step, or `composer install` on the server.
- **Configuration through `.env` only.**
- **Security first.** Consider SSRF, header/CRLF injection, request smuggling,
  cache poisoning, open redirects, XSS via rewriting, path traversal, host
  header attacks, and DoS limits for every change that touches request or
  response handling.

## Development setup

```bash
git clone https://github.com/moonshadowrev/mirrorgate.git
cd mirrorgate
composer install        # dev tools only (PHPUnit, PHPStan)
cp .env.example .env    # then edit for local testing
php -S 127.0.0.1:8080 -t public
```

Never commit `.env` or any real credentials.

## Checks

```bash
composer lint              # php -l on all files plus CLI guard checks
composer stan              # PHPStan
composer test              # PHPUnit
composer test:standalone   # dependency-free test runner (no vendor/ needed)
composer e2e               # local demo origin plus mirror, asserted with curl
composer daemon-selftest   # daemon checks
composer check             # lint, stan and test together
```

Run at least `composer check` before opening a pull request, and say in the PR
which checks you did not run.

## Pull requests

1. Open an issue first for larger changes so the approach can be agreed.
2. Create a topic branch from `main`.
3. Keep changes focused; unrelated cleanups belong in separate PRs.
4. Add or update tests, including security regression tests for fixes.
5. Update documentation, `.env.example`, and `CHANGELOG.md` (under
   "Unreleased") when behavior or configuration changes.
6. Fill in the pull request template.

## Code style

- Follow the existing style and `.editorconfig`.
- Use `declare(strict_types=1);` and typed properties, parameters, and returns.
- Namespace `MirrorGate\` maps to `src/` (PSR-4).
- Prefer clear, small classes. Comment the reason for non-obvious security
  decisions.
- Do not log or echo secrets, `.env` values, or origin internals.

## Commit messages

Use short, imperative subjects (for example, "Reject userinfo in origin URLs").
Explain the why in the body when it is not obvious.

## License

By contributing, you agree that your contributions are licensed under the
[Apache License 2.0](LICENSE), consistent with section 5 of the license.
