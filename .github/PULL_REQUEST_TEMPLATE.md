## Summary

<!-- What does this change and why? Link related issues (e.g. "Closes #123"). -->

## Type of change

- [ ] Bug fix
- [ ] New feature
- [ ] Documentation
- [ ] Refactor / maintenance
- [ ] Security hardening

## Checklist

- [ ] Code runs on PHP 8.3 and has no deprecations on 8.4/8.5 (no dynamic properties, explicit nullable types)
- [ ] No new required Composer runtime dependencies (only ext-curl, ext-json, ext-mbstring are required)
- [ ] `php -l` passes on changed files; tests and static analysis pass locally
- [ ] Tests added or updated for the change
- [ ] Documentation and `.env.example` updated where behavior or configuration changed
- [ ] `CHANGELOG.md` updated under "Unreleased"
- [ ] No secrets, real hostnames, or personal data committed

## Security considerations

<!-- Does this touch request parsing, URL handling, header/cookie rewriting, cache keys, or admin endpoints?
Describe how SSRF, header injection, cache poisoning, and path traversal were considered. Write "N/A" if not applicable. -->

## Testing notes

<!-- How did you verify this? PHP version, web server / SAPI, and relevant .env settings. -->
