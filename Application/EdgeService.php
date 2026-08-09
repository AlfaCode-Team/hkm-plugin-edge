<?php

declare(strict_types=1);

namespace Plugins\Edge\Application;

use Plugins\Edge\API\Contracts\EdgeServiceContract;
use Plugins\Edge\Domain\CacheProfile;
use Plugins\Edge\Domain\EdgePlan;
use Plugins\Edge\Domain\ServerStack;
use Plugins\Edge\Domain\Strategy;
use Plugins\Edge\Domain\TlsConfig;
use Plugins\Edge\Domain\TlsMode;
use Plugins\Edge\Infrastructure\ConfigRenderer;
use Plugins\Edge\Infrastructure\HostsFileWriter;
use Plugins\Edge\Infrastructure\ServiceRenderer;
use Plugins\Edge\Infrastructure\SiteCollector;
use Plugins\Edge\Infrastructure\StreamConfigWriter;
use Plugins\Edge\Infrastructure\SystemProbe;

/**
 * Orchestrates the edge workflow: probe the host → decide a strategy (in the
 * ServerStack domain) → render the config → write and (optionally) validate +
 * reload the web server. Holds no state; safe to resolve per request/command.
 */
final class EdgeService implements EdgeServiceContract
{
    public function __construct(
        private readonly SystemProbe $probe,
        private readonly SiteCollector $sites,
        private readonly ConfigRenderer $renderer,
        private readonly HostsFileWriter $hosts,
        private readonly ServiceRenderer $services = new ServiceRenderer(),
        private readonly StreamConfigWriter $streamWriter = new StreamConfigWriter(),
    ) {}

    /**
     * Render process-manager units for the OpenSwoole projects in scope. PHP-FPM
     * projects are skipped — php-fpm already supervises those workers.
     *
     * @return array<string, string> unit/program name => file contents
     */
    public function serviceUnits(string $format = 'systemd', bool $all = false, ?string $appEnv = null, string $user = 'www-data'): array
    {
        $env  = $appEnv ?? (string) edge_config('app_env', 'production');
        $out  = [];
        foreach ($this->sites->sites($all, $env) as $site) {
            if (!$this->services->supports($site)) {
                continue;
            }
            $name = $this->services->unitName($site);
            $out[$name] = $format === 'supervisor'
                ? $this->services->supervisor($site, $user)
                : $this->services->systemd($site, $user, $user);
        }

        return $out;
    }

    public function detect(): ServerStack
    {
        return $this->probe->detect();
    }

    public function phpFpm(): array
    {
        return [
            'version' => $this->probe->phpCliVersion(),
            'socket'  => $this->probe->phpFpmSocket(),
            'active'  => $this->probe->phpFpmActive(),
        ];
    }

    public function plan(bool $all = false, ?string $tlsMode = null, ?string $sslCert = null, ?string $sslKey = null, ?string $appEnv = null, ?string $force = null): EdgePlan
    {
        // Resolve any operator override FIRST, so a pinned single-server run does
        // not waste time probing (or reporting on) the server it will never use.
        $forced   = $this->forcedStrategy($force);
        $stack    = $this->probe->detect(
            nginxOnly:  $forced === Strategy::NginxOnly,
            apacheOnly: $forced === Strategy::ApacheOnly,
        );
        $strategy = $stack->strategy($forced);

        // When both servers run and nginx already has an SNI stream splitter,
        // reuse it (default) rather than writing a second `stream {}` block. A
        // forced single-server strategy never renders a stream block at all.
        $reuseStream = $strategy === Strategy::NginxStream
            && (bool) edge_config('reuse_stream', true)
            && $stack->nginxHasStreamConfig;

        // The application environment is chosen EXPLICITLY (CLI flag, else the
        // configured/exported APP_ENV) and is what the cache profile derives from
        // — never the kernel mode. Keeps kernel selection and app env independent.
        $env     = $appEnv ?? (string) edge_config('app_env', 'production');
        $profile = CacheProfile::fromAppEnv($env);

        // Default: ONLY the current project. --all renders every registered one.
        // Public domains → server config; local (.local/.test) → /etc/hosts.
        $sites = $this->sites->sites($all, $env);

        [$path, $body] = $this->renderer->render($strategy, $sites, $this->resolveTls($tlsMode, $sslCert, $sslKey), $stack, $profile, $reuseStream);

        return new EdgePlan($stack, $strategy, $sites, $this->sites->localDomains($all), $path, $body, $reuseStream);
    }

    /**
     * Resolve an EXPLICIT strategy override into a Strategy, or null for
     * auto-detection. Accepts the CLI value ($force) first, then the
     * EDGE_FORCE_STRATEGY config default. `nginx-only` and `apache-only` pin a
     * single server with NO fallback; anything else means "auto-detect".
     */
    private function forcedStrategy(?string $force): ?Strategy
    {
        $value = strtolower(trim($force ?? (string) edge_config('force_strategy', '')));

        return match ($value) {
            'nginx-only', 'nginx'   => Strategy::NginxOnly,
            'apache-only', 'apache' => Strategy::ApacheOnly,
            default                 => null,
        };
    }

    /**
     * Merge the CLI's TLS overrides with the config defaults into a concrete
     * TlsConfig. An unknown/empty mode falls back to the configured default, and
     * that to `ssl`. cert/key default to the config's ssl.* paths.
     */
    private function resolveTls(?string $tlsMode, ?string $sslCert, ?string $sslKey): TlsConfig
    {
        $mode = TlsMode::tryFrom((string) ($tlsMode ?? ''))
            ?? TlsMode::tryFrom((string) edge_config('tls.mode', 'ssl'))
            ?? TlsMode::Ssl;

        return new TlsConfig(
            $mode,
            (string) ($sslCert ?? edge_config('ssl.cert')),
            (string) ($sslKey ?? edge_config('ssl.key')),
        );
    }

    /**
     * /etc/hosts is a DEVELOPER-machine concern (local .local/.test domains) —
     * a live server resolves its public domains through DNS. So this refuses to
     * run unless the launcher marked the invocation as dev (`hkm … --dev`,
     * which exports HKM_DEV=1), unless explicitly forced.
     */
    public function syncHosts(bool $remove = false, bool $dryRun = false, bool $all = false, bool $force = false): array
    {
        if (!$force && !$this->isDev()) {
            return [
                'ok'      => false,
                'path'    => (string) edge_config('hosts.path', '/etc/hosts'),
                'count'   => 0,
                'message' => 'refusing to touch the hosts file outside dev mode — run with `--dev` (or pass --force). '
                           . 'On a live server public domains resolve via DNS, not /etc/hosts.',
            ];
        }

        return $this->hosts->sync(
            domains: $this->sites->localDomains($all),
            ip:      (string) edge_config('hosts.ip', '127.0.0.1'),
            path:    (string) edge_config('hosts.path', '/etc/hosts'),
            remove:  $remove,
            dryRun:  $dryRun,
        );
    }

    /** Did the launcher run us in dev mode (`--dev` exports HKM_DEV=1)? */
    private function isDev(): bool
    {
        return filter_var(env('HKM_DEV', 'false'), FILTER_VALIDATE_BOOL);
    }

    public function apply(bool $reload = true, bool $dryRun = false, ?bool $manageHosts = null, bool $all = false, ?string $tlsMode = null, ?string $sslCert = null, ?string $sslKey = null, ?string $appEnv = null, ?string $force = null): array
    {
        $plan = $this->plan($all, $tlsMode, $sslCert, $sslKey, $appEnv, $force);

        // 1. Local domains → /etc/hosts. DEV ONLY: a live server resolves its
        //    public domains via DNS, so outside dev we silently skip this step
        //    rather than touching the machine's hosts file.
        $hosts = null;
        if (($manageHosts ?? (bool) edge_config('manage_hosts', true)) && $this->isDev()) {
            $hosts = $this->syncHosts(dryRun: $dryRun, all: $all);
        }

        if ($plan->strategy === Strategy::None) {
            return [
                'ok'       => ($hosts['ok'] ?? true) === true,
                'strategy' => Strategy::None->value,
                'hosts'    => $hosts,
                'message'  => 'No active web server detected — only local hosts were synced.',
            ];
        }

        if ($dryRun) {
            return [
                'ok'       => true,
                'dry_run'  => true,
                'strategy' => $plan->strategy->value,
                'path'     => $plan->targetPath,
                'sites'    => \count($plan->sites),
                'served'   => $this->servedCount($plan),
                'contents' => $plan->contents,
                'hosts'    => $hosts,
                'stream'   => $this->mergeExistingStream($plan, dryRun: true),
            ];
        }

        // 2. Write the server config atomically (temp file + rename) so a live
        //    include never sees a half-written file.
        $dir = dirname($plan->targetPath);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return ['ok' => false, 'strategy' => $plan->strategy->value, 'hosts' => $hosts, 'message' => "Cannot create directory {$dir}"];
        }
        // Back up the CURRENT good config before overwriting it. The new file
        // is written over the live path and only then validated, so without a
        // backup a failed `nginx -t` left the broken config in place — and the
        // next reload, by anyone (a deploy, logrotate, a reboot), would load it.
        // The failure would surface later and somewhere else.
        $backup = null;
        if (is_file($plan->targetPath)) {
            $backup = $plan->targetPath . '.bak-' . bin2hex(random_bytes(4));
            if (!@copy($plan->targetPath, $backup)) {
                return ['ok' => false, 'strategy' => $plan->strategy->value, 'hosts' => $hosts, 'message' => "Cannot back up {$plan->targetPath} — refusing to overwrite it"];
            }
        }

        $tmp = $plan->targetPath . '.tmp';
        if (@file_put_contents($tmp, $plan->contents) === false || !@rename($tmp, $plan->targetPath)) {
            @unlink($tmp);
            if ($backup !== null) {
                @unlink($backup);
            }
            return ['ok' => false, 'strategy' => $plan->strategy->value, 'hosts' => $hosts, 'message' => "Failed to write {$plan->targetPath}"];
        }

        $siteCount = \count($plan->sites);
        $steps = ["wrote {$plan->targetPath} ({$siteCount} project site(s))"];

        // 3. Reusing an existing SNI splitter → merge our domains into ITS map,
        //    editing that host file (e.g. nginx.conf) in place. Fail loudly: a
        //    write we cannot complete (usually a missing sudo) must not look ok.
        $stream = $this->mergeExistingStream($plan, dryRun: false);
        if ($stream !== null) {
            if (($stream['ok'] ?? false) !== true) {
                return ['ok' => false, 'strategy' => $plan->strategy->value, 'path' => $plan->targetPath, 'steps' => $steps, 'hosts' => $hosts, 'stream' => $stream, 'message' => 'stream map update failed: ' . ($stream['message'] ?? 'unknown error')];
            }
            $added = \count($stream['added'] ?? []);
            $steps[] = ($stream['changed'] ?? false)
                ? "stream: merged {$added} domain(s) into {$stream['file']}"
                : "stream: {$stream['file']} already current";
        }

        if ($reload) {
            $isApache = $plan->strategy === Strategy::ApacheOnly;
            $testCmd   = (string) edge_config($isApache ? 'commands.apache_test'   : 'commands.nginx_test');
            $reloadCmd = (string) edge_config($isApache ? 'commands.apache_reload' : 'commands.nginx_reload');

            [$tc, $tout] = $this->probe->run($testCmd);
            $steps[] = "test: {$testCmd} → " . ($tc === 0 ? 'ok' : 'FAILED');
            if ($tc !== 0) {
                // ROLL BACK. Leaving an invalid config at the live path turns a
                // caught, reported failure into an outage the next time anything
                // reloads the server.
                if ($backup !== null && @rename($backup, $plan->targetPath)) {
                    $steps[] = "rolled back {$plan->targetPath} to the previous config";
                } elseif ($backup !== null) {
                    $steps[] = "WARNING: could not restore {$plan->targetPath} from {$backup} — restore it manually before reloading";
                }

                return ['ok' => false, 'strategy' => $plan->strategy->value, 'path' => $plan->targetPath, 'steps' => $steps, 'hosts' => $hosts, 'message' => trim($tout)];
            }

            // Validated: the backup has done its job.
            if ($backup !== null) {
                @unlink($backup);
                $backup = null;
            }

            [$rc, $rout] = $this->probe->run($reloadCmd);
            $steps[] = "reload: {$reloadCmd} → " . ($rc === 0 ? 'ok' : 'FAILED');
            if ($rc !== 0) {
                return ['ok' => false, 'strategy' => $plan->strategy->value, 'path' => $plan->targetPath, 'steps' => $steps, 'hosts' => $hosts, 'message' => trim($rout)];
            }
        }

        // Nothing validated the write (reload was skipped), or it validated and
        // the backup was already removed. Either way do not leave a stale
        // .bak-* beside the live config.
        if ($backup !== null) {
            @unlink($backup);
        }

        return [
            'ok'       => true,
            'strategy' => $plan->strategy->value,
            'path'     => $plan->targetPath,
            'sites'    => \count($plan->sites),
            'served'   => $this->servedCount($plan),
            'steps'    => $steps,
            'hosts'    => $hosts,
            'stream'   => $stream,
        ];
    }

    /**
     * How many of the plan's sites actually produced a `server {}` block. A site
     * with no domain at all is collected but serves nothing, and the difference
     * between "sites in scope" and "sites served" is exactly what tells an
     * operator why the rendered file looks empty — so the commands report both.
     */
    private function servedCount(EdgePlan $plan): int
    {
        $served = 0;
        foreach ($plan->sites as $site) {
            if ($site->servesPublic()) {
                $served++;
            }
        }

        return $served;
    }

    /**
     * When the plan reuses an existing nginx SNI splitter, merge the plan's public
     * domains into that host file's `map $ssl_preread_server_name` in place
     * (pointing them at the nginx backend). Returns null when there is nothing to
     * merge (not a reuse plan, or the splitter file could not be located).
     *
     * @return array<string, mixed>|null
     */
    private function mergeExistingStream(EdgePlan $plan, bool $dryRun): ?array
    {
        if (!$plan->reuseStream) {
            return null;
        }
        $file = $this->probe->nginxStreamConfigFile((string) edge_config('paths.stream', ''));
        if ($file === null) {
            return null;
        }

        return $this->streamWriter->merge(
            file:    $file,
            domains: $this->planPublicDomains($plan),
            backend: (string) edge_config('stream_backend', 'nginx_backend'),
            dryRun:  $dryRun,
        );
    }

    /**
     * Every public domain across the plan's sites (deduplicated, order-preserved)
     * — the hostnames that must route SNI → nginx.
     *
     * @return list<string>
     */
    private function planPublicDomains(EdgePlan $plan): array
    {
        $domains = [];
        foreach ($plan->sites as $site) {
            foreach ($site->publicDomains as $d) {
                $domains[$d] = true;
            }
        }

        return array_keys($domains);
    }
}
