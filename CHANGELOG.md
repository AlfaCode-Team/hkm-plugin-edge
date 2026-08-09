# Changelog

All notable changes to the Edge plugin are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Because Edge's *product* is a web-server configuration file, "breaking" here
means **the generated config behaves differently on an existing deployment** —
not only that a PHP signature changed. Always preview an upgrade with:

```bash
hkm cli -p <project> edge:apply --dry-run
```

## [2.0.0] — 2026-08-09

A security-hardening release. Every generated vhost gains defence-in-depth that
was previously absent, the Apache output is brought up to the same standard as
nginx, and two bugs that produced a silently empty config are fixed.

### Breaking

Each item ships with a switch to restore the old behaviour.

- **Apache: only `index.php` executes PHP.** The vhost used to install
  `<FilesMatch "\.php$"> SetHandler proxy:fcgi`, which runs *any* `.php` file
  that reaches the docroot — a leftover `adminer.php`, or a file placed there by
  an upload bug. nginx has always been front-controller-only; Apache now matches.
  *If your app exposes a second PHP entry point, add a `<Files>` grant for it in
  a project config override.*

- **Apache: `.htaccess` is no longer read** (`AllowOverride None`). It costs a
  stat on every path segment of every request, and any bug that lets a file be
  written into the docroot would otherwise become config injection. The
  front-controller rewrite your `public/.htaccess` used to provide is now emitted
  by the vhost itself (`FallbackResource /index.php`), so routing is unaffected.
  *Custom `.htaccess` rules — auth, redirects, extra headers — must move into the
  vhost.*

- **More file types are refused.** `deny_ext` now also covers private keys and
  keystores (`pem key crt cer csr der p12 pfx jks asc gpg kdbx`), databases
  (`sqlite3 db db3`), editor leftovers (`orig rej save swo tmp`) and archives
  (`zip tar gz tgz bz2 xz 7z rar iso dmg`); `deny_files` refuses build metadata
  by name (`composer.json`, `composer.lock`, `package.json`, `Dockerfile`, …).
  *A public root that legitimately serves a `.zip` download or a `.yml` now
  returns 403 — narrow the list with `EDGE_DENY_EXT` / `EDGE_DENY_FILES`.*

- **Uploads download instead of rendering.** Under `storage`, `uploads` and
  `media`, risky extensions (`svg html htm xml js …`) are served with
  `Content-Disposition: attachment` and a sandbox CSP. *If you serve SVG icons or
  JS from those paths, set `EDGE_UPLOAD_PATHS` to exclude them, narrow
  `EDGE_UPLOAD_RISKY_EXT`, or set `EDGE_UPLOAD_MODE=off`.*

- **Apache: verbs outside `EDGE_ALLOWED_METHODS` are refused** (`<LimitExcept>`),
  matching the guard nginx already had.

- **Rate limiting only covers the application.** `limit_req` / `limit_conn` moved
  from server scope into the PHP front controller (nginx FPM), the proxy location
  (OpenSwoole) — static assets are never throttled. At server scope a single page
  load pulling 30 assets burned 31 tokens against the zone, so a real visitor was
  429'd on CSS while the expensive dynamic request went unprotected.

- **A project whose domains are all local is now served.** Previously those
  domains were filtered out of the server config unless `HKM_DEV=1`, producing a
  file with no `server {}` block at all while still reporting "1 site(s)".

### Added

- **httpoxy guard** (CVE-2016-5385) — `fastcgi_param HTTP_PROXY "";` on nginx,
  `RequestHeader unset Proxy early` on Apache. A `Proxy:` request header
  otherwise arrives as `$_SERVER['HTTP_PROXY']` and reroutes the application's
  own outbound HTTP through an attacker's proxy. `EDGE_HTTPOXY_GUARD`.
- **Isolation headers** — `Permissions-Policy`, `Cross-Origin-Opener-Policy`,
  `Cross-Origin-Resource-Policy`, on both fronts. `EDGE_PERMISSIONS_POLICY`,
  `EDGE_COOP`, `EDGE_CORP`; set any to empty to omit it.
- **Content-Security-Policy support**, opt-in and report-only capable —
  `EDGE_CSP`, `EDGE_CSP_REPORT_ONLY`. Nothing is emitted until configured,
  because a wrong policy breaks a site silently.
- **Upstream header stripping** — `X-Powered-By` and friends are removed
  (`fastcgi_hide_header` / `proxy_hide_header` / `Header always unset`). PHP's
  `expose_php` was publishing the exact patch level. `EDGE_HIDE_HEADERS`.
- **Slow-request guards** — `client_header_timeout`, `client_body_timeout`,
  `send_timeout`, `keepalive_timeout`, `large_client_header_buffers`,
  `reset_timedout_connection`; `RequestReadTimeout` on Apache. nginx's 60-second
  defaults let a handful of dribbling sockets hold every worker slot.
  `EDGE_TIMEOUTS` and `EDGE_*_TIMEOUT`.
- **Configurable request-body ceiling** — `EDGE_MAX_BODY` (was hardcoded `25m`),
  converted to bytes automatically for Apache's `LimitRequestBody`.
- **PROXY protocol** — `EDGE_PROXY_PROTOCOL`, off by default. Behind the SNI
  stream splitter a plain L4 `proxy_pass` rewrote the source address, so every
  visitor reached the backend as `127.0.0.1`: per-IP rate limiting collapsed into
  one shared bucket and, with the Cloudflare real-IP prelude enabled,
  `127.0.0.1` is a *trusted* proxy — so any client could send its own
  `CF-Connecting-IP` and choose its rate-limit identity. Both ends are emitted
  from the single flag, and never on a directly-reachable vhost.
- **Origin lockdown** — `EDGE_CLOUDFLARE_ONLY` (allow Cloudflare's ranges, deny
  the rest) and `EDGE_ORIGIN_PULL_CA` (Authenticated Origin Pulls / mTLS), for
  traffic that would otherwise reach the origin directly and bypass the WAF.
- **Apache parity** — the security headers, directory/extension/filename denials,
  dotfile denial for *files* as well as directories, per-site logs, TLS protocol
  and cipher pinning, and `TraceEnable Off`.
- `served` count in the `apply()` result, and a CLI explanation when a run
  renders nothing.
- `SystemProbe::detect(?bool $nginxOnly, ?bool $apacheOnly)` — a pinned strategy
  no longer probes (or reports on) the server it will never use.
- Test suites `SecurityHardeningTest` and `NginxRateLimitPlacementTest`
  (32 new tests), including an injection suite that feeds hostile config values
  and asserts nothing escapes into the generated file.

### Fixed

- **`ssl_stapling` never actually stapled.** No `resolver` was emitted, so nginx
  logged "no resolver defined to resolve …" and carried on. `EDGE_RESOLVER`.
- **Throttled requests looked like outages.** nginx answers `limit_req` with 503
  by default, which this vhost maps to `/50x.html` — so a rate-limited visitor
  got the server-error page. Now `limit_req_status 429` / `limit_conn_status 429`
  and `limit_req_log_level warn`.
- **An empty render is explained.** `edge:apply` reports `(3 site(s), 1 served)`
  when a project in scope produced no vhost, and lists the likely causes instead
  of printing a config with nothing in it; `edge:status` flags the site.
- Config values containing `..` are dropped from `deny_dirs` and the upload
  paths rather than emitted as a location outside the site.
- **Declared-config drift.** `module.json` declared 63 environment variables
  while the plugin read 84; it now declares all 114, so the manifest is a
  complete inventory of the plugin's knobs.

### Documentation

- `config/edge.php` is fully documented — every key carries its environment
  variable, default, accepted values, when to change it and why it matters,
  grouped into six sections with a "how to change a value" preface.
- `doc/EDGE_FLOW_GUIDE.pdf` — architecture, request/apply flow diagrams, a
  per-command reference and the security model.

## [1.0.1] — 2026-08-06

### Fixed

- Roll back a failed edge config instead of leaving it at the live path, and
  allow-list the binaries the plugin is permitted to execute.

### Added

- Declare the kernel version the plugin supports.
- Enforcing CI with standalone composer dependencies and the plugin's own tests.

## [1.0.0] — 2026-08-06

Initial release: host probing, strategy selection (SNI stream splitter /
nginx-only / Apache-only), per-project vhost generation for PHP-FPM and
OpenSwoole, `/etc/hosts` synchronisation, systemd and supervisor unit rendering,
and the `edge:status`, `edge:apply`, `edge:hosts` and `edge:service` commands.

[2.0.0]: https://github.com/AlfaCode-Team/hkm-plugin-edge/releases/tag/v2.0.0
[1.0.1]: https://github.com/AlfaCode-Team/hkm-plugin-edge/releases/tag/v1.0.1
[1.0.0]: https://github.com/AlfaCode-Team/hkm-plugin-edge/releases/tag/v1.0.0
