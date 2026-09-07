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

## [2.1.0] — 2026-09-07

### Fixed — Edge ran on Debian and quietly did nothing anywhere else

Three host assumptions were compiled into the plugin as literals. Each one is
the difference between a config that loads and one that does not, and none of
them was visible on the platform they were written for.

- **Service detection could never succeed without systemd.** `active()` falls
  back to `pgrep` when `systemctl` is absent, but `pgrep` was missing from the
  command allow-list, so the fallback was refused (exit 126) before it ran. On
  macOS, in every container, on Alpine and the BSDs, both servers reported
  inactive and `edge:apply` answered "No active web server detected" on a host
  with nginx running. `pgrep` is now allow-listed.

  Allow-listing it was necessary but NOT sufficient, and macOS stayed broken
  after it: nginx rewrites its own process title, so the accounting name BSD
  `pgrep` matches against is the whole string `nginx: master process
  /opt/homebrew/opt/nginx/bin/nginx …`, and `pgrep -x nginx` — which demands an
  EXACT match — finds nothing while nginx is plainly serving on :80. `active()`
  now falls back from `-x` to a plain `pgrep`, a substring match on the process
  NAME. Deliberately not `pgrep -f`, which matches the whole command line and
  would count `tail -f /var/log/nginx/error.log` as a running nginx. Linux is
  unaffected: there the title rewrite lands in `cmdline` while `/proc/<pid>/comm`
  stays `nginx`, so `-x` matches and the fallback never runs.

- **Per-site log directories were hard-coded** to `/var/log/nginx` and
  `/var/log/apache2`. Neither exists on a Homebrew macOS host (`<prefix>/var/log/nginx`)
  and the Apache one is `/var/log/httpd` on RHEL/Fedora — and nginx REFUSES to
  start when an `access_log` directory is missing, so the generated config could
  not be loaded at all. The directory is now detected from the server's own
  compiled-in path (`nginx -V --error-log-path`, `apachectl -V`), then the
  platform's conventional location, and when nothing resolves the per-site log
  directives are OMITTED (the vhost falls back to the server's global log)
  rather than naming a directory that is not there. Override with
  `EDGE_NGINX_LOG_DIR` / `EDGE_APACHE_LOG_DIR`.

- **`http2 on;` was emitted unconditionally.** That directive arrived in nginx
  1.25.1; on anything older it is an "unknown directive" and `nginx -t` fails —
  which is every current LTS (Ubuntu 22.04 ships 1.18, RHEL 9 ships 1.20,
  Debian 12 ships 1.22). Edge now reads the installed version and emits the
  `listen … ssl http2` parameter where the directive would fail. `EDGE_HTTP2`
  (`auto` | `on` | `listen` | `off`) pins the choice when the nginx that LOADS
  the config is not the one Edge probed.

### Fixed — `edge:apply` crashed instead of reporting that there was nothing to do

`strategy: none` means no web server is running, so the plan carries no vhost
and no reload target — no `path`, no `contents`. Both output branches
dereferenced them anyway. With `--dry-run` that was two "Undefined array key"
warnings and then a `TypeError` from `muted(null)`: a stack trace instead of an
answer. Without it the failure was quieter and worse — `Edge applied [none]`,
reporting success for work that never happened.

It is not an error state (local hosts may still have been synced), so it now
reports what happened, names what would change it, and stops. The message also
stops claiming "only local hosts were synced" when `--no-hosts` meant nothing
was.

### Added — the host's own start command when a server is installed but stopped

`strategy: none` on a Homebrew Mac is the DEFAULT, not a malfunction: `brew
install nginx` leaves the service stopped. Read on that machine, "No active web
server detected" is indistinguishable from "this tool does not work here", and
the difference is one command. Edge now names it — `sudo brew services start
nginx` under Homebrew (with why `sudo` is needed for :80/:443), `sudo systemctl
start …` under systemd, `net start …` on Windows — for exactly the servers that
are installed and not running. It never starts anything itself: running a web
server as root is the operator's decision, not a side effect of a config
command.

### Added — Windows detection

Every probe was POSIX-only, so on Windows nothing was ever found: `command -v`
is a POSIX **shell builtin** that cmd.exe does not have, and neither `systemctl`
nor `pgrep` exists. `where` now replaces `command -v`, and `sc query` then
`tasklist` replace the running-check. Both Windows probes read the OUTPUT rather
than the exit code, which is the whole subtlety — `tasklist` exits 0 even when
it matched nothing, and `sc query` exits 0 for a service that exists but is
STOPPED, so trusting either would report every installed server as running.

Detection works; the caveat is documented in the README. Neither server is a
Windows *service* unless it was installed as one, so `tasklist` carries the
common case of `nginx.exe` started by hand, and the `net start` hint says
plainly that it applies only to a real service. The `config/edge.php` paths
still assume a POSIX layout — a Windows host must set `EDGE_NGINX_PATH`,
`EDGE_APACHE_PATH` and the log directories explicitly.

### Fixed — the SNI-splitter strategy could not produce a loadable config

When nginx AND Apache are both running and nginx has the `stream` module, Edge
wrote the `stream {}` splitter and the backend `server {}` vhosts into ONE file.
nginx accepts that nowhere: included at the main context it refuses `server`,
included inside `http {}` it refuses `stream`. Every apply of that strategy —
the whole reason the strategy exists — failed its own configtest.

The two halves are now two files, each included where it belongs:

- `EDGE_STREAM_PATH` — the `stream {}` splitter, at the nginx MAIN context;
- `EDGE_NGINX_PATH` — the backend vhosts, inside `http {}` (the same file every
  other strategy writes).

`edge:apply` writes, backs up and rolls back BOTH together — a plan that rolled
back half would leave a splitter and a vhost set describing different
topologies. `--dry-run` prints both, and `edge:status` names both targets with
the context each belongs in. When an existing splitter on the host is reused,
there is still exactly one file (the vhosts) and the host's own splitter is
merged into as before.

Nothing can regress from this: no include of the old combined file has ever
loaded.

### Fixed — Apache directives that were not gated on their module

Apache treats an unknown directive as a hard configtest failure, and three of
them were emitted unconditionally:

- **`SSLProtocol … +TLSv1.3`** needs Apache 2.4.36+ on OpenSSL 1.1.1+, which the
  version alone does not tell you: macOS ships a current 2.4.67 linked against
  LibreSSL, and RHEL 7 / Ubuntu 18.04 predate 1.1.1. `SSLProtocol: Illegal
  protocol 'TLSv1.3'` took the whole vhost down — so every TLS mode of the
  Apache strategy failed on those hosts. Edge now asks Apache itself (a
  throwaway config that includes the host's own, then states the directive) and
  drops the token where it is refused, with a comment saying why so the
  remaining line is not mistaken for a deliberate TLS 1.2-only policy.
- **`SetEnv`** is mod_env. Without it the project's run-env was also what stopped
  the vhost loading; the keys are now listed in a comment instead.
- **`RewriteEngine`** is mod_rewrite, which **Debian and Ubuntu do not enable by
  default** (`a2enmod rewrite`), so `--tls=both` failed on a stock Apache. The
  HTTPS redirect now degrades to `Redirect permanent` (mod_alias) and then to an
  explanatory comment.

### Changed

- The PHP-FPM socket search covers the Homebrew (`/opt/homebrew`, `/usr/local`)
  and BSD (`/var/run`) layouts as well as the Linux ones. The Linux resolution
  order is unchanged.
- `ssl.cert` / `ssl.key`, `hosts.path` and the `edge:service` target directory
  default per platform instead of to the Debian path. The certificate and its
  key always resolve to the SAME directory — `/etc/ssl/certs` exists on macOS
  while `/etc/ssl/private` does not, and half a layout filed the pair in two
  places.
- `edge:service --write` on a host running neither systemd nor supervisor now
  asks for an explicit `--write=<dir>` instead of creating `/etc/systemd/system`
  and writing a unit nothing will ever read.
- `nginx -V` and `apachectl -V` are read once per run instead of once per
  question.

### Added

- `EDGE_NGINX_LOG_DIR`, `EDGE_APACHE_LOG_DIR`, `EDGE_HTTP2`, `EDGE_SYSTEMD_DIR`,
  `EDGE_SUPERVISOR_DIR`.
- `ConfigRenderer::renderStream()` and `EdgePlan::files()` / `EdgePlan::streamPath`
  — the second file of a split plan. `apply()` returns `stream_path` alongside
  `path`.
- `Infrastructure\HostPaths` — where this host keeps its logs and which nginx
  dialect it speaks. Constructing it does no I/O, so the renderer stays pure;
  `HostPaths::fromBanners()` makes the resolution testable against a recorded
  banner from any platform rather than only the machine the suite runs on.

### Note for upgraders

`edge:apply --dry-run` shows the difference. On a Debian/Ubuntu host with nginx
1.25.1+ and mod_rewrite enabled, the single-server strategies render
byte-identically to 2.0.3. Elsewhere they change — which is the point of the
release.

The SNI-splitter strategy changes everywhere, and its include lines must be
updated: what used to be one `include hkm-edge-stream.conf;` becomes the
splitter at the main context PLUS `include hkm-edge-nginx.conf;` inside
`http {}`. `edge:apply` prints both paths with their contexts after a
successful apply.

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
