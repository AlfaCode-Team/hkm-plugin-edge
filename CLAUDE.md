# Edge — Plugin Context for Claude

> Plugin for the **AlfacodeTeam PhpServicePlatform** (Sentinel) kernel.
> Package `alfacode-team/hkm-plugin-edge` · namespace `Plugins\Edge\` · solves `edge.routing`
>
> This file is the rule set for THIS repository. It is self-contained for
> day-to-day work; the kernel-wide contracts it builds on are linked at the
> bottom. This is NOT Laravel, NOT Symfony, NOT Slim — do not suggest those
> frameworks' patterns, classes or conventions.

---

## WORKING RULES — VERSION CONTROL (ABSOLUTE)

**NEVER run `git commit`, `git push`, `git tag`, or `gh release` / `gh pr`
unless the user explicitly asks for it in that message.**

Write the changes, run the tests, report what changed — and stop. The user
decides when work is committed, pushed, tagged or released.

This matters more here than in an application repo. This plugin is a **published
package**: every project that requires it consumes what you push. A pushed tag is
immutable on Packagist and cannot be reused, so a premature release is not an
`undo`, it is a new version plus an explanation.

When the work is done, say what is uncommitted and let the user choose.

---

## WORKING RULES — VERIFICATION (READ THE DEFINITION)

**Before calling something you did not write, open its definition.** Not a call
site elsewhere — the definition.

This is not caution for its own sake; it is the measured failure mode of this
codebase. A review of ~5,600 lines of freshly written code found **15 defects**
that `php -l`, PHPStan and `tsc --noEmit` all passed. Every one was a coherent
but false belief about something the code called — a trait method that lived in a
different trait, a link pointing at a POST-only route, a framework default
assumed from the shape of an API rather than read from its source.

```
✓ Trait method   → find the trait that DECLARES it (traits compose; the obvious one is often wrong)
✓ Signature      → read it; argument order and names are not inferable
✓ Route          → read module.json for its METHOD, path, filters[] and requires[]
✓ env() key      → confirm it is in THIS module.json config[], or the boot fails
✓ Kernel API     → read the kernel source, not the shape of the call site
✓ Sibling plugin → read that plugin's API/Contracts/, never its internals
✓ A default that is a URL → someone will click it; confirm it resolves
```

State plainly what you did NOT verify. "Static analysis is clean" is a weak claim
here and must never be presented as evidence the code works. Code that has never
run against a real request has not been tested — say so in those words.

---

## WHAT THIS PLUGIN IS

| | |
|---|---|
| `solves` | `edge.routing` |
| `requires` | — |
| `exposes` | `EdgeServiceContract` |
| `emits` | — |
| Activation | on-demand — a consumer declares it in `requires[]` |
| Namespace | `Plugins\Edge\` |
| Version | `2.0.0` |

---

## `module.json` IS THE SINGLE SOURCE OF TRUTH

Routes, jobs, commands, views, emitted events and every environment variable this
plugin reads are declared in `module.json`. The kernel compiles them at boot.

```
✗ Declaring a route in a PHP file — routes exist ONLY in module.json
✗ Reading an env var that is not in config[] — ValidateConfigStage fails the boot
✗ Naming a requires[] entry that is not some module's solves domain — fails the boot
✗ Putting a port or contract CLASS name in requires[] — module DOMAINS only
✗ Dispatching an event whose name is not in emits[] — nothing is subscribed to it
```

`config[]` is also what `hkm plugins enable edge` seeds into the project's
`.env`, so a declared `default` is the value the operator actually receives.
Three shapes, and the difference is load-bearing:

| Declaration | Written to `.env` as | Why |
|---|---|---|
| has `default` | `KEY=value` (active) | the documented default, working out of the box |
| `required`, no default | `KEY=` (active, empty) | `''` counts as MISSING, so the boot still fails loudly until a real secret is supplied |
| optional, no default | `# KEY=` (commented) | `''` is a VALUE and would silently beat this plugin's own internal default |

### Environment variables (`config[]`)

| Key | Type | Required | Default |
|---|---|---|---|
| `APP_ENV` | string | no | — |
| `EDGE_ALLOWED_BINARIES` | string | no | — |
| `EDGE_ALLOWED_METHODS` | string | no | — |
| `EDGE_APACHE_BACKEND` | string | no | — |
| `EDGE_APACHE_PATH` | string | no | — |
| `EDGE_APACHE_RELOAD_CMD` | string | no | — |
| `EDGE_APACHE_TEST_CMD` | string | no | — |
| `EDGE_APP_BACKEND` | string | no | — |
| `EDGE_APP_ENV` | string | no | — |
| `EDGE_BEHIND_SNI_ROUTER` | bool | no | — |
| `EDGE_CACHE_ASSETS` | bool | no | — |
| `EDGE_CACHE_ASSETS_TTL` | int | no | — |
| `EDGE_CACHE_CLOUDFLARE` | bool | no | — |
| `EDGE_CACHE_HTML` | bool | no | — |
| `EDGE_CLIENT_BODY_TIMEOUT` | string | no | — |
| `EDGE_CLIENT_HEADER_TIMEOUT` | string | no | — |
| `EDGE_CLOUDFLARE_HEADER` | string | no | — |
| `EDGE_CLOUDFLARE_ONLY` | bool | no | — |
| `EDGE_CLOUDFLARE_RANGES` | string | no | — |
| `EDGE_CLOUDFLARE_REAL_IP` | bool | no | — |
| `EDGE_COMPRESSION` | string | no | — |
| `EDGE_COOP` | string | no | — |
| `EDGE_CORP` | string | no | — |
| `EDGE_CORS` | string | no | — |
| `EDGE_CORS_CREDENTIALS` | bool | no | — |
| `EDGE_CORS_HEADERS` | string | no | — |
| `EDGE_CORS_METHODS` | string | no | — |
| `EDGE_CORS_ORIGINS` | string | no | — |
| `EDGE_CSP` | string | no | — |
| `EDGE_CSP_REPORT_ONLY` | bool | no | — |
| `EDGE_DENY_DIRS` | string | no | — |
| `EDGE_DENY_EXT` | string | no | — |
| `EDGE_DENY_FILES` | string | no | — |
| `EDGE_DEV_VHOST` | bool | no | — |
| `EDGE_EXCLUDE_DOMAINS` | string | no | — |
| `EDGE_EXTRA_DOMAINS` | string | no | — |
| `EDGE_FORCE_STRATEGY` | string | no | — |
| `EDGE_FPM_SOCKET` | string | no | — |
| `EDGE_HEALTH_CHECK` | bool | no | — |
| `EDGE_HEALTH_PATH` | string | no | — |
| `EDGE_HIDE_HEADERS` | string | no | — |
| `EDGE_HOSTS_IP` | string | no | — |
| `EDGE_HOSTS_PATH` | string | no | — |
| `EDGE_HSTS` | bool | no | — |
| `EDGE_HSTS_DEV_MAX_AGE` | int | no | — |
| `EDGE_HSTS_MAX_AGE` | int | no | — |
| `EDGE_HSTS_PRELOAD` | bool | no | — |
| `EDGE_HSTS_SUBDOMAINS` | bool | no | — |
| `EDGE_HTTPOXY_GUARD` | bool | no | — |
| `EDGE_HTTP_PORT` | int | no | — |
| `EDGE_HTTP_PRELUDE` | bool | no | — |
| `EDGE_INJECT_KERNEL_ENV` | bool | no | — |
| `EDGE_KEEPALIVE_TIMEOUT` | string | no | — |
| `EDGE_LARGE_CLIENT_HEADER_BUFFERS` | string | no | — |
| `EDGE_LISTEN_PORT` | int | no | — |
| `EDGE_LOCAL_IN_SERVER` | bool | no | — |
| `EDGE_LOCAL_TLDS` | string | no | — |
| `EDGE_LOG_BUFFER` | string | no | — |
| `EDGE_LOG_FLUSH` | string | no | — |
| `EDGE_LOG_FORMAT` | string | no | — |
| `EDGE_MANAGE_HOSTS` | bool | no | — |
| `EDGE_MAX_BODY` | string | no | — |
| `EDGE_NGINX_BACKEND` | string | no | — |
| `EDGE_NGINX_DEBUG_LOG` | bool | no | — |
| `EDGE_NGINX_PATH` | string | no | — |
| `EDGE_NGINX_RELOAD_CMD` | string | no | — |
| `EDGE_NGINX_SSL_PORT` | int | no | — |
| `EDGE_NGINX_STATUS` | bool | no | — |
| `EDGE_NGINX_TEST_CMD` | string | no | — |
| `EDGE_ORIGIN_PULL_CA` | string | no | — |
| `EDGE_PERMISSIONS_POLICY` | string | no | — |
| `EDGE_PER_SITE_LOGS` | bool | no | — |
| `EDGE_PROJECTS_DIR` | string | no | — |
| `EDGE_PROXY_PROTOCOL` | bool | no | — |
| `EDGE_RATE_CONN_LIMIT` | int | no | — |
| `EDGE_RATE_CONN_SIZE` | string | no | — |
| `EDGE_RATE_CONN_ZONE` | string | no | — |
| `EDGE_RATE_LIMIT` | bool | no | — |
| `EDGE_RATE_REQ_BURST` | int | no | — |
| `EDGE_RATE_REQ_NODELAY` | bool | no | — |
| `EDGE_RATE_REQ_RATE` | string | no | — |
| `EDGE_RATE_REQ_SIZE` | string | no | — |
| `EDGE_RATE_REQ_ZONE` | string | no | — |
| `EDGE_RELOAD` | bool | no | — |
| `EDGE_RESOLVER` | string | no | — |
| `EDGE_RESOLVER_TIMEOUT` | string | no | — |
| `EDGE_REUSE_STREAM` | bool | no | — |
| `EDGE_SEND_TIMEOUT` | string | no | — |
| `EDGE_SERVE_MODEL` | string | no | — |
| `EDGE_SSL_CERT` | string | no | — |
| `EDGE_SSL_CIPHERS` | string | no | — |
| `EDGE_SSL_KEY` | string | no | — |
| `EDGE_SSL_PROTOCOLS` | string | no | — |
| `EDGE_SSL_STAPLING` | bool | no | — |
| `EDGE_STREAM_BACKEND` | string | no | — |
| `EDGE_STREAM_PATH` | string | no | — |
| `EDGE_SWOOLE_BALANCE` | string | no | — |
| `EDGE_SWOOLE_BASE_PORT` | int | no | — |
| `EDGE_SWOOLE_COMMAND` | string | no | — |
| `EDGE_SWOOLE_FAIL_TIMEOUT` | string | no | — |
| `EDGE_SWOOLE_HOST` | string | no | — |
| `EDGE_SWOOLE_KEEPALIVE` | int | no | — |
| `EDGE_SWOOLE_KEEPALIVE_REQUESTS` | int | no | — |
| `EDGE_SWOOLE_KEEPALIVE_TIMEOUT` | string | no | — |
| `EDGE_SWOOLE_MAX_FAILS` | int | no | — |
| `EDGE_SWOOLE_PHP` | string | no | — |
| `EDGE_SWOOLE_PORT` | int | no | — |
| `EDGE_SWOOLE_WORKERS` | string | no | — |
| `EDGE_SWOOLE_WS_PATH` | string | no | — |
| `EDGE_TIMEOUTS` | bool | no | — |
| `EDGE_TLS_MODE` | string | no | — |
| `EDGE_UPLOAD_MODE` | string | no | — |
| `EDGE_UPLOAD_PATHS` | string | no | — |
| `EDGE_UPLOAD_RISKY_EXT` | string | no | — |

---

## THE FIVE ACCESS RULES — ABSOLUTE — RUNTIME ENFORCED

```
Controller  →  Service      (published contract interface ONLY)
Service     →  Repository  AND  Gateway   (the ONLY layer calling both)
Repository  →  DatabasePort ONLY          (no HTTP, no vendor SDK)
Gateway     →  Vendor SDK ONLY            (no DB, no services)
Domain      →  NOTHING EXTERNAL           (zero imports outside Domain/)
```

`ModuleContainer::bindInternal()` enforces these at runtime — violations throw
`ScopeViolationException`. A `bindInternal` binding is unreachable from any other
module even when that module declares this one in `requires[]`; only the
contracts in `exposes[]` cross the boundary.

Layer rules that apply to every file in this repo:

- **Controllers are ≤3 lines** — DTO in, service call, `Response` out. No business logic.
- **Services own the transaction + event shape** — `collector->beginCollection()`,
  `transaction->begin()`, work, `commit()`; on `\Throwable` `rollback()` **and**
  `collector->discard()`. Integration events dispatch **only after** a successful
  commit, never inside the `try`.
- **Repositories translate `\PDOException`** into `RepositoryException`, and scope
  every query by tenant where the data is tenant-owned.
- **Gateways translate every vendor exception** into `GatewayException`. No vendor
  exception type escapes the gateway.
- **Domain has zero external imports** and never dispatches — entities buffer
  events and `releaseEvents()`.
- **Money is integer cents in a value object**, never a float.
- **No `static` mutable state in request-scoped classes** — it leaks between
  requests under OpenSwoole.
- **`hash_equals()` for every token/secret comparison**, never `===`.

---

## WHAT THIS PLUGIN IS

Generates the **host's web-server front config** from the platform's registered
domains, adapting to whatever is actually running on the machine. It probes the
host, picks a strategy, renders the matching config, then validates and reloads
the server.

| Detected stack | Strategy | Rendered config |
|---|---|---|
| nginx **and** Apache active, nginx has `stream` | `nginx-stream` | nginx SNI (L4) stream splitter — listed domains → nginx (`:444`), everything else → Apache (`:8443`) |
| only nginx active (or Apache inactive, or no `stream`) | `nginx-only` | plain nginx reverse-proxy vhost |
| only Apache active | `apache-only` | Apache SSL `VirtualHost` |
| neither | `none` | nothing — reports and stops |

Override detection with `--nginx-only` / `--apache-only` (no fallback), or set a
deploy default via `EDGE_FORCE_STRATEGY`.

## THIS PLUGIN WRITES TO THE HOST — TREAT EVERY CHANGE AS OPERATIONAL

It edits real web-server configuration, may need `sudo`, and reloads a live
server. The properties below are safety-critical, not stylistic.

- **The existing-splitter merge is surgical and idempotent.** When nginx already
  declares an SNI `stream {}` splitter, Edge does not write a second, conflicting
  one. It emits only the internal backend vhosts and merges the platform's
  domains **into the existing `map`**, inside a marked sub-block placed just
  before the `default` line. Hand-written entries are never touched, and a domain
  already present anywhere in the map is never re-added.
- **A failed write fails the whole apply loudly.** Writing `nginx.conf` usually
  needs `sudo`; a partial apply that reports success is worse than a clean failure.
- **`--dry-run` previews the exact diff** without touching anything. Use it before
  any apply you are not certain of.
- `EDGE_REUSE_STREAM=0` disables the merge and always writes Edge's own block.

```
✗ Applying without --dry-run on a machine you did not just inspect
✗ Writing a config that has not passed the server's own test command
  (EDGE_NGINX_TEST_CMD / EDGE_APACHE_TEST_CMD) before reload
✗ Duplicating a map entry, or emitting a second splitter — both take the site down
✗ Shell-interpolating a domain or path into a rendered config or a reload command
  — EDGE_ALLOWED_BINARIES exists because command construction here is attack surface
✗ Relaxing EDGE_HSTS / EDGE_CSP / EDGE_DENY_* defaults to "make something work"
```

With 114 declared config keys, `module.json` is the only complete reference for
what this plugin reads — check it there rather than inferring from a template.

---

## TESTING — USE THE GROUND, NOT A HAND-ROLLED BOOTSTRAP

A plugin is not a library: it is declared in `module.json`, compiled by the boot
pipeline, loaded through a dependency graph and resolved in a scoped container.
Almost everything that goes wrong with one goes wrong in that machinery, so a
unit test of its service proves very little — and standing up a whole project to
find out is why plugins go untested.

```php
$ground = PluginGround::for(Provider::class, DependencyProvider::class)
    ->as(Identity::asAdmin('tenant-1'))
    ->env(['SOME_KEY' => 5])
    ->boot();

$ground->db()->onQuery('from things', ['id' => 1]);
$ground->get('/things')->status();                 // the real HttpPipeline
$ground->service(SomeServiceContract::class);      // resolved in this plugin's scope
$ground->events()->dispatched('thing.created');
$ground->destroy();                                // ALWAYS — restores $_ENV + Paths
```

Three behaviours that are easy to get wrong:

- **Security is OPEN by default.** `BindSecurityStage` refuses an empty layer list
  (fail-closed), so the ground installs an allow-all stand-in. Passing any layer to
  `security()` REPLACES it — which is what a test about auth wants.
- **Events are recorded from `emits[]` only.** `EventBus` is `final` with no
  wildcard, so an event dispatched under a name the manifest does not declare is
  never recorded. It reads as "nothing dispatched"; check the manifest first.
- **Required config with no default is filled with a PLACEHOLDER** so the boot
  proceeds. `placeholders()` lists them — anything asserted while one is in play is
  asserted against a stand-in.

`hkm plugin:check` runs the static GDA + UI checks the boot cannot catch and exits
non-zero, so it gates CI. `hkm plugin:probe` boots this plugin for real and
resolves `requires[]` transitively.

```
✗ Booting this plugin against a REAL project root in a test — the kernel writes
  compiled manifests under the active project, so it overwrites that project's
  route/service/config manifests and leaves them that way. The ground's temp
  workspace exists for exactly this.
✗ Leaking a ground (no destroy()) — $_ENV stays mutated and Paths points at a
  deleted directory; the symptom is an unrelated later test failing.
✗ Trusting a "route is protected" assertion while its filter is STUBBED —
  stubbedFilters() lists aliases that did NOT run (auth/throttle come from
  SecurityFilters; load it, or use ->realFilters(), when the filter is the subject).
```

---

## WHAT NEVER TO GENERATE IN THIS REPO

```
✗ git commit / push / tag, or gh pr / gh release, without being asked in that message
✗ Laravel / Symfony / Slim patterns, classes or conventions
✗ Eloquent, Doctrine, Active Record or any ORM — LetMigrate + DatabasePort only
✗ Routes defined in PHP — module.json is the only place
✗ Env vars read but not declared in module.json config[]
✗ Port or contract CLASS names in requires[] — module domains only
✗ Business logic in a Controller — max 3 lines: DTO → service → Response
✗ Integration events dispatched inside a try{} — ONLY after commit
✗ A catch block that rolls back without collector->discard() — phantom events
✗ Vendor exceptions (\PDOException, Stripe, Guzzle) escaping their layer
✗ Another plugin's internal class imported — use its published contract
✗ Authorization decisions in a SecurityLayer — those belong in the Service
✗ float for money — integer cents in a value object
✗ === for token comparison — always hash_equals()
✗ Static mutable state in request-scoped classes — leaks across Swoole requests
✗ Injecting Request or Response into a Service or Repository
✗ Hand-writing ON DUPLICATE KEY / ON CONFLICT — call $db->upsert() (driver-portable)
✗ Adding a kernel change to make this plugin work — the kernel stays domain-ignorant
```

---

## KERNEL REFERENCE

The kernel documents the contracts; this repo documents the plugin. When they
disagree, the code wins — read the definition.

| Topic | Guide |
|---|---|
| Architecture + request lifecycle | [00_SENTINEL_OVERVIEW](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/docs/guides/00_SENTINEL_OVERVIEW.md) |
| Kernel internals | [01_KERNEL](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/docs/guides/01_KERNEL.md) |
| Module contract + `module.json` | [02_MODULE](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/docs/guides/02_MODULE.md) |
| Domain layer | [03_DOMAIN](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/docs/guides/03_DOMAIN.md) |
| Service layer + transaction/event pattern | [04_SERVICE](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/docs/guides/04_SERVICE.md) |
| Repository layer | [05_REPOSITORY](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/docs/guides/05_REPOSITORY.md) |
| Gateway layer | [06_GATEWAY](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/docs/guides/06_GATEWAY.md) |
| Controllers + DTO validation | [07_CONTROLLER](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/docs/guides/07_CONTROLLER.md) |
| Events | [08_EVENTS](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/docs/guides/08_EVENTS.md) |
| SecurityGateway + Identity | [09_SECURITY](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/docs/guides/09_SECURITY.md) |
| Testing + port fakes | [10_TESTING](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/docs/guides/10_TESTING.md) |
| Workers + jobs | [12_WORKER](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/docs/guides/12_WORKER.md) |
| Antipatterns | [13_ANTIPATTERNS](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/docs/guides/13_ANTIPATTERNS.md) |
| Writing a plugin | [16_PLUGINS](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/docs/guides/16_PLUGINS.md) |
| Migrations (LetMigrate) | [18_MIGRATIONS](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/docs/guides/18_MIGRATIONS.md) |
| CSRF | [21_CSRF](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/docs/guides/21_CSRF.md) |
| Routing cookbook | [30_ROUTING_COOKBOOK](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/docs/guides/30_ROUTING_COOKBOOK.md) |

Sibling plugins are separate repositories under
`github.com/AlfaCode-Team/hkm-plugin-<name>`. Depend on one through its
`exposes[]` contract and a `requires[]` domain — never by reaching into its tree.
