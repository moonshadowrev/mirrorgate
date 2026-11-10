# Rewriting

A mirror is only convincing if the visitor stays on the mirror host. MirrorGate therefore rewrites the origin host name to the mirror host name in responses, in the opposite direction in some request headers, and strips headers that would leak the origin. This page lists exactly what is changed, what is deliberately left alone, and how to deal with the cases that cannot be rewritten automatically.

Settings are in [configuration.md](configuration.md#rewriting).

## Principles

- **Replacement text comes from configuration only.** The mirror URL used in rewritten content is built from `MIRROR_URL`, `MIRROR_ALIASES`, `HOST_MAP` and the prefix-mode settings, never from the visitor's `Host` header or any other request data.
- **One pass, with strict host boundaries.** All origin hosts are matched in a single regular-expression pass (longest first). A host only matches as a whole host name: `overlord.team` is replaced, but `evil-overlord.team`, `notoverlord.team`, `overlord.team.evil.com`, `overlord.teams` and `admin@overlord.team` (an e-mail address) are left alone.
- **Fail safe.** If a regular expression fails (invalid UTF-8, backtracking limit) or a rewritten JSON document would no longer be valid JSON, the original body is sent unchanged and a warning is logged. A body whose rewrite failed is never cached.
- **Content types are never changed**, no inline script is ever injected, and bodies in binary or non-ASCII-compatible formats are not touched.
- **Bounded.** Bodies above `REWRITE_MAX_BYTES` (default 8M, sitemaps and `robots.txt`: `REWRITE_SITEMAP_MAX_BYTES`) are streamed unmodified. Compressed bodies are decoded with a size limit (a "gzip bomb" aborts the rewrite). If four times the body size would not fit into the remaining `memory_limit`, the body is passed through unrewritten and not cached.

## What gets rewritten

Classification uses the response `Content-Type` (parameters ignored) and, for a few types, the path.

| Content | Rewritten | Condition |
|---|---|---|
| `text/html`, `application/xhtml+xml` | Yes, in URL positions | `REWRITE_HTML` |
| `text/css` | Yes | `REWRITE_CSS` |
| `application/json`, `application/ld+json`, `*+json` | Yes | `REWRITE_JSON` |
| `application/manifest+json`, `application/x-web-app-manifest+json` | Yes | `REWRITE_JSON` |
| `text/xml`, `application/xml`, `*+xml` (RSS, Atom, sitemaps) | Yes | `REWRITE_XML` |
| `text/plain` at exactly `/robots.txt` | `Sitemap:` and `Host:` lines | Skipped with `SEO_MODE=canonical-origin` |
| `text/javascript`, `application/javascript` and similar | Host literals only | `REWRITE_JS=true`, or `SERVICE_WORKER_MODE=rewrite` for service worker requests |
| `text/x-component` (React Server Components) | Host literals | `REWRITE_RSC=true` |
| `text/event-stream` | Never | Streamed as is |
| `image/svg+xml`, images, fonts, video, audio, archives, PDF, WebAssembly, source maps, protobuf, unknown types | Never | Passed through |

A response with status `204`, `206` or `304`, any `HEAD` response, and the paths `/.well-known/assetlinks.json`, `/.well-known/apple-app-site-association`, `/.well-known/openid-configuration`, `/.well-known/oauth-authorization-server`, `/_next/image*` and `*.map` are never rewritten, because rewriting them would break app verification, OAuth discovery or image optimisation.

Before rewriting, MirrorGate skips bodies that start with a binary signature (WebAssembly, PNG, GIF, JPEG, PDF, ZIP, gzip) or contain a NUL byte in the first 8 KiB, and bodies in `UTF-16` or `UTF-32` (detected from the header, a BOM or an HTML meta tag). All other character sets, including `windows-1256`, are handled byte-wise.

### Forms of a host that are recognised

The replacer finds these spellings of an origin host (case-insensitive), with or without an explicit port equal to the origin port, and with a trailing dot:

- `https://overlord.team`, `http://overlord.team` and protocol-relative `//overlord.team`,
- JSON-escaped slashes `https:\/\/overlord.team` and double-escaped `https:\\/\\/overlord.team`,
- URL-encoded `https%3A%2F%2Foverlord.team` (and the lowercase form) and `%2F%2Foverlord.team`,
- HTML entities `https:&#x2F;&#x2F;overlord.team` and `https:&#47;&#47;overlord.team`,
- `ws://` and `wss://` (see [WebSockets](#websockets)),
- the bare host `overlord.team` for hosts mapped one-to-one (the primary host and `HOST_MAP` hosts), in places where bare hosts are replaced (below).

Scheme-bearing forms take the mirror's scheme and host (for example `https://overload.ir`), keeping the escaping style of the match. Protocol-relative forms stay protocol-relative.

### HTML

HTML is scanned with a small tokenizer, not a DOM parser. Only these positions are changed:

- URL attributes on any tag: `href`, `src`, `action`, `formaction`, `poster`, `data`, `cite`, `background`, `longdesc`, `manifest`, `ping`, `codebase`, `usemap`, `xlink:href`, and the common lazy-loading attributes `data-src`, `data-href`, `data-url`, `data-background`, `data-bg`, `data-poster`, `data-lazy-src`, `data-original`, `data-image`. Relative URLs and `data:` URIs are left alone. Attribute values that contain HTML entities (`&#46;`, `&sol;`) are handled.
- `srcset`, `imagesrcset` and `data-srcset`, candidate by candidate, preserving the descriptors (`1x`, `640w`).
- The `style` attribute and `<style>` blocks, as CSS.
- `<meta http-equiv="refresh" content="5; url=...">`.
- `<meta>` tags for `og:url`, `og:image`, `og:video`, `og:audio` (and sub-properties), `twitter:image`, `twitter:url`, `twitter:player` and `msapplication-*`. Other meta tags, including `application-name` and `description`, are untouched.
- `<base href>`, `<link>` elements (canonical, alternate, preload, icon, manifest, stylesheet and so on), `<a>`, `<form>`, `<iframe>`, `<img>`, and all other elements through the attribute list above.
- Inline event handlers (`onclick` and others): absolute origin URLs are mapped; bare host names only with `REWRITE_JS=true`.
- `srcdoc` and HTML comments: only scheme-bearing and protocol-relative URL forms are replaced.
- `<script type="application/ld+json">`, `application/json`, `importmap`, `speculationrules`, and `<script id="__NEXT_DATA__">`: the host replacer runs over the raw text (never through `json_decode`), so escaped slashes stay escaped.
- Other `<script>` bodies are rewritten only when `REWRITE_JS=true`; `self.__next_f.push(...)` chunks only when `REWRITE_RSC=true`.
- Visible text is left alone by default: a sentence such as "Welcome to overlord.team" stays as it is. `REWRITE_VISIBLE_TEXT=true` also replaces hosts in text nodes. The contents of `<textarea>`, `<title>`, `<xmp>`, `<noembed>` and `<noframes>` are not scanned, even with `REWRITE_VISIBLE_TEXT=true`.

**Subresource Integrity.** Rewriting changes the bytes of CSS and, with `REWRITE_JS=true`, JavaScript, so their `integrity` hashes would no longer match and browsers would block them. With `REWRITE_STRIP_INTEGRITY=true` (default) MirrorGate removes the `integrity` attribute from `<link rel="stylesheet">` (and `preload` with `as=style`) always, and from `<script src>` and `modulepreload`/`preload as=script` only when `REWRITE_JS=true`. This is done only when the URL is relative or points to a handled origin host or a mirror host. A hash on a third-party CDN resource is never touched, since that resource is not modified.

**CSP in HTML.** `<meta http-equiv="Content-Security-Policy">` is handled like the response header according to `CSP_MODE` (`strip` removes the tag).

### CSS

`url(...)` (unquoted, single-quoted, double-quoted, with whitespace), `@import "..."`, `@import url(...)`, `image-set()` and `@font-face` sources. Relative URLs and `data:` URIs are untouched; `sourceMappingURL` comments are not rewritten.

### JSON, manifests, XML, sitemaps, robots.txt

- **JSON and web app manifests:** the raw text goes through the replacer. When the input was valid JSON and the output would not be, the original is kept.
- **XML, RSS, Atom and sitemaps:** the replacer runs over the raw text, which covers `<loc>`, `<image:loc>`, `<video:content_loc>`, `xhtml:link href`, RSS `<link>` and `<guid>`, Atom `href` and CDATA sections. Entities such as `&amp;` are preserved.
- **robots.txt:** only the `Sitemap:` and `Host:` lines are rewritten. Other directives are not touched.

### JavaScript

With `REWRITE_JS=true`, full host literals (including the escaped and encoded forms above) are replaced. The script is never tokenised or parsed, so only a literal host name that appears in the file text is affected. Use it when the page's bundles hard-code an API host that you also mirror. It does not understand string concatenation or computed URLs; see [Limits](#limits-and-things-that-cannot-be-rewritten).

## Headers

### Removed from responses

These are never sent to the visitor, because they reveal the origin's infrastructure or no longer make sense:

- `Server`, `X-Powered-By`, `Via` (replaced by MirrorGate's own when `ADD_VIA=true`), `Date` (the web server sets its own), `Age`, `Public-Key-Pins`, `Expect-CT`, `NEL`, `Report-To`, `Reporting-Endpoints`, `Server-Timing`.
- Proxy and infrastructure hints: `X-Served-By`, `X-Backend`, `X-Upstream`, `X-Varnish`, `X-Cache`, `X-Cache-Hits`, `X-Request-Id`, `X-Runtime`, `X-Request-Start`, `X-Real-IP`, `X-Timer`, `Traceparent`, `Tracestate`, `X-Cloud-Trace-Context`, `X-Sendfile`, `X-Accel-*`, `X-LiteSpeed-*`.
- Prefix families: `CF-*`, `X-Envoy-*`, `X-Kong-*`, `X-Vercel-*`, `X-Amz-*`, `X-Amzn-*`, `X-Azure-*`, `X-Nextjs-*`, `X-Forwarded-*`, `X-Trace*`, `X-B3-*`, `X-Original-*`.
- Hop-by-hop headers (`Connection`, `Keep-Alive`, `Transfer-Encoding`, `Upgrade`, `TE`, `Trailer`, `Proxy-*`).
- `Content-Length`, `Content-MD5`, `Digest`, `Content-Digest`, `Repr-Digest` (recomputed or dropped), and `ETag`, `Accept-Ranges` and `Content-Encoding` when the body was changed.
- `Alt-Svc` when `STRIP_ALT_SVC=true`.
- `Strict-Transport-Security` always; with `HSTS_MODE=own` and an `https` mirror, your own `HSTS_VALUE` is added.
- Anything you list in `RESPONSE_HEADER_STRIP` (exact names, or prefixes ending in `*`).

The same removals apply to buffered and to streamed responses.

### Rewritten

| Header | Behaviour |
|---|---|
| `Location`, `Content-Location`, `Refresh` | Absolute and protocol-relative URLs of handled hosts are mapped to the mirror; root-relative and relative values stay as they are. Control characters, tabs, backslashes and the schemes `javascript:`, `data:`, `vbscript:`, `file:`, `blob:`, `about:` and `filesystem:` are refused: the visitor gets a `502` page instead. With `REDIRECT_EXTERNAL=block`, redirects to unrelated hosts are refused as well; with `pass` (default) they are forwarded unchanged. The redirect status code is preserved |
| `Link` | URLs in `<...>` targets are mapped (commas inside URLs are handled) |
| `Set-Cookie` | See [Cookies](#cookies) |
| `Access-Control-Allow-Origin` | Mapped when it names a handled origin host. `*` is kept. A value that equals the visitor's `Origin` and is a mirror host is kept. Any other value is dropped; an arbitrary request origin is never reflected. `Vary: Origin` is added when the value is not `*`. `Access-Control-Allow-Credentials` is kept only if the allow-origin header was kept |
| `Content-Security-Policy`, `-Report-Only` (and `X-Content-Security-Policy`, `X-WebKit-CSP`) | See [CSP](#content-security-policy) |
| `X-Robots-Tag` | Passed on; with `SEO_MODE=noindex` merged with `noindex, nofollow` |
| `X-Frame-Options`, `Timing-Allow-Origin`, `Permissions-Policy`, `Feature-Policy` | Absolute URLs mapped |
| Any other header containing `//` | Absolute origin URLs mapped (for example `X-Pingback`, `SourceMap`) |

The `Content-Type` is preserved exactly. `Cache-Control`, `Expires`, `Last-Modified`, `Vary`, `Content-Language` and `Content-Disposition` pass through unchanged.

### Cookies

Each `Set-Cookie` line is handled on its own (the `Expires` attribute contains a comma).

- `Domain` is dropped by default (`COOKIE_DOMAIN_MODE=drop`), turning the cookie into a host-only cookie of the mirror host. With `rewrite` a `Domain` that equals or covers a known origin host is mapped to the matching mirror host, provided the browser would accept it for the host that is being served; see [multi-instance.md](multi-instance.md#cookies) for the trade-off.
- `Path`, `Expires`, `Max-Age`, `HttpOnly` and `SameSite` are kept. `Partitioned` is kept when the cookie is `Secure`.
- `Secure` is added when the mirror is `https` and `COOKIE_FORCE_SECURE=true`, and also when the cookie is `SameSite=None` or `Partitioned`. For an `http` mirror, `SameSite=None` is downgraded to `Lax` (browsers reject `None` without `Secure`).
- `__Host-` cookies never keep a `Domain`.
- Cookies are dropped if they contain control characters, have a name longer than 256 characters or a line longer than 4096 characters. At most 40 cookies are passed per response.
- Cookies named `mg_*`, `__Host-mg_*` and `__Secure-mg_*` belong to MirrorGate and are dropped in both directions. They are never forwarded to the origin either.

### Content-Security-Policy

With `CSP_MODE=rewrite` (default), host sources that name the origin become mirror hosts (including wildcard sources such as `https://*.overlord.team` and `wss://` sources); keywords, nonces, scheme-only sources and third-party hosts stay. `report-uri` targets are mapped, kept when relative or third party, and dropped when they point at an origin-internal host; `report-to` is removed together with the `Report-To` header. If the HTML or an inline script was modified, the `'sha256-...'`, `'sha384-...'` and `'sha512-...'` sources are removed from `script-src`, `style-src` and their `-elem`/`-attr` variants, since they would no longer match.

`CSP_MODE=strip` removes CSP headers and CSP meta tags. `CSP_MODE=passthrough` leaves them unchanged, which usually breaks pages whose policy names the origin host.

A response that uses CSP nonces (header or meta tag) is never stored in the cache, because a nonce is per response. See [caching.md](caching.md#what-is-stored).

### Request headers toward the origin

- `Host` is always the origin host from `ORIGIN_URL`.
- `Origin` and `Referer` are mapped back from mirror URLs to origin URLs, so the origin's own CSRF and same-origin checks accept the request.
- `X-Forwarded-Proto`, `X-Forwarded-Host` and `X-Forwarded-Port` describe the **mirror** (from `MIRROR_URL`), so frameworks that trust forwarded headers generate mirror URLs themselves. `X-Forwarded-For` carries the resolved client address (not on cache fills). `Via: 1.1 mirrorgate` is added when `ADD_VIA=true`.
- All client-supplied versions of forwarding and identity headers (`Forwarded`, `X-Forwarded-*`, `X-Real-IP`, `X-Client-IP`, `True-Client-IP`, `CF-*`, `X-Original-URL`, `X-Rewrite-URL`, `X-HTTP-Method-Override`, `X-Middleware-*`, `X-Remote-User` and others) are removed first, plus anything in `REQUEST_HEADER_STRIP`. Header names with an underscore are dropped.
- `Cookie` loses mirror-reserved cookies. `Authorization` and `Cookie` are forwarded only when `FORWARD_AUTHORIZATION` and `FORWARD_COOKIES` allow it, and never on cache fills.
- With `REWRITE_REQUEST_BODY=true`, mirror URLs in `application/x-www-form-urlencoded` and JSON request bodies up to 1 MiB are mapped back to origin URLs. This helps forms that submit absolute URLs. It is off by default.

## SEO modes

`SEO_MODE` decides how search engines and sharing sites see the mirror.

| Mode | Canonical link, `og:url`, hreflang, JSON-LD, sitemap | Additional |
|---|---|---|
| `mirror` (default) | Rewritten to the mirror | The mirror presents itself as the canonical site |
| `canonical-origin` | `<link rel="canonical">` and `og:url` stay on the origin; sitemaps and `robots.txt` are not rewritten | HTML responses get `Link: <origin-url/path>; rel="canonical"`, so search engines treat the origin as the original |
| `noindex` | Rewritten as in `mirror` | Every proxied response gets `X-Robots-Tag: noindex, nofollow` (merged with the origin's directives), and HTML gets `<meta name="robots" content="noindex,nofollow">` before `</head>`. `robots.txt` is not turned into `Disallow: /` |

Mirroring someone else's site as a duplicate can harm both sites in search results, and may violate their terms or copyright. Choose `canonical-origin` or `noindex` when the mirror exists for access, not for ranking.

## Service workers

Browsers fetch a service worker script with the request header `Service-Worker: script`. `SERVICE_WORKER_MODE` controls the response:

- `passthrough` (default): the worker is delivered like any other script. It still contains the origin host, since JavaScript is not rewritten unless `REWRITE_JS=true`.
- `rewrite`: the worker script is rewritten like JavaScript (host literals).
- `block`: MirrorGate answers with a small kill-switch worker that clears all caches, unregisters itself and reloads open pages. Use this when an origin's worker would intercept requests and send them to the origin directly, bypassing the mirror.

## WebSockets

PHP cannot serve WebSockets, so `ws://` and `wss://` literals that point at the origin are **left untouched** unless `WS_PUBLIC_URL` is set. With it, they are replaced by the public address of the [daemon](daemon.md#websocket-urls). Without a daemon, the browser connects to the origin directly, which works only if the origin accepts connections from the mirror's page origin. A normal HTTP request with an `Upgrade: websocket` header that reaches PHP gets a `501`.

## Next.js and similar frameworks

- `/_next/static/*` assets are cached as static files and need no rewriting. Their content-hashed names make them safe to cache for a long time: `CACHE_RULES=/_next/static/*=31536000`.
- `/_next/image` responses are never rewritten, and `/_next/image` requests are not treated as rewritable.
- `<script id="__NEXT_DATA__">` is rewritten, so page props that contain absolute origin URLs follow the mirror.
- Requests with the headers `RSC`, `Next-Router-State-Tree`, `Next-Router-Prefetch`, `Next-Url` or `Next-Action` are neither cached nor rewritten (unless `REWRITE_RSC=true`), because React Server Components payloads contain length-prefixed rows, and changing the host length would corrupt them. If the mirror host has a different length than the origin host, leave `REWRITE_RSC` off. Server-rendered HTML that embeds RSC data in `self.__next_f.push` chunks is likewise left alone unless you opt in.
- Hard-coded API hosts in bundles (for example `https://api.overlord.team/v1`) are not covered by HTML rewriting. Options, in order of preference: run an instance for the API host and map it with `HOST_MAP`; or set `REWRITE_JS=true` so literal host names in bundles are replaced; or configure the application to use relative URLs.
- Login flows, middleware that checks `Origin`, and anything signed with the origin's host name usually fail on a mirror. See [security.md](security.md#the-mirror-operator-sees-everything).

## Limits and things that cannot be rewritten

- **URLs that are assembled at runtime** (`"https://" + host + "/api"`), obfuscated, base64-encoded or fetched from another service cannot be found by a text replacer.
- **Third-party hosts** are untouched by design; only origin and mapped hosts are replaced. A page that loads fonts or analytics from third-party hosts keeps doing so.
- **Signed URLs and tokens** that embed the origin host are invalidated when the host changes.
- **Cross-site assumptions of the origin** (OAuth redirect URIs, payment callbacks, allowed domains lists) refer to the origin host and will not accept the mirror.
- **Non-rewritten types** (images, PDF, WebAssembly, fonts) containing the origin host are passed through.
- **Visible text** with the host name is unchanged unless `REWRITE_VISIBLE_TEXT=true`.
- **Content above the size limits** is streamed unmodified.

## Checking what the mirror sends

```bash
# Links in the page should name the mirror, not the origin.
curl -s https://overload.ir/ | grep -o 'overlord\.team' | head

# Redirects should stay on the mirror.
curl -sI https://overload.ir/old-path | grep -i '^location'

# Cookies should be host-only and Secure.
curl -sI https://overload.ir/ | grep -i '^set-cookie'

# Internals must not leak.
curl -sI https://overload.ir/ | grep -iE '^(server|x-powered-by|via|x-envoy|cf-|x-vercel)'
```

The repository's end-to-end script (`bash scripts/e2e.sh`) asserts many of these behaviours against the demo origin, including links, escaped-slash JSON, `srcset`, host maps and cookies.
