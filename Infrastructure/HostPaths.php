<?php

declare(strict_types=1);

namespace Plugins\Edge\Infrastructure;

use Plugins\Edge\Domain\ServerStack;

/**
 * Where THIS host keeps the things a generated config must name, and which
 * dialect of nginx it speaks.
 *
 * Every path below used to be a literal inside the renderer, which quietly made
 * Edge a Debian tool: `/var/log/nginx` does not exist on macOS (Homebrew logs to
 * `<prefix>/var/log/nginx`) and `/var/log/apache2` is `/var/log/httpd` on
 * RHEL/Fedora — and nginx REFUSES to start when an `access_log` directory is
 * missing, so a wrong guess is not a cosmetic difference, it is a config that
 * cannot load.
 *
 * The object is a plain value: constructing it does no I/O, so `new HostPaths()`
 * is the documented default (the Debian layout, which is what the templates were
 * written against) and the renderer stays pure. `detect()` is the one entry point
 * that talks to the host — it reads the paths the installed servers were COMPILED
 * with (`nginx -V`, `apachectl -V`), falls back to the conventional locations,
 * and finally to '' meaning "no usable log directory: omit the directives rather
 * than emit a config that will not load".
 *
 * Operator overrides (EDGE_NGINX_LOG_DIR / EDGE_APACHE_LOG_DIR) are taken
 * VERBATIM and are never existence-checked — a deploy that creates the directory
 * later is a legitimate setup, and second-guessing it would be the tool
 * overruling an explicit instruction.
 */
final readonly class HostPaths
{
    public function __construct(
        /** Directory for per-site nginx logs; '' = emit no per-site log directives. */
        public string $nginxLogDir = '/var/log/nginx',
        /** Directory for per-site Apache logs; '' = emit no per-site log directives. */
        public string $apacheLogDir = '/var/log/apache2',
        /** Installed nginx version ("1.24.0"), or null when it could not be read. */
        public ?string $nginxVersion = null,
        /** Does this Apache accept `SSLProtocol … +TLSv1.3`? See SystemProbe. */
        public bool $apacheTls13 = true,
    ) {}

    /**
     * Probe the host. The ONLY method here that performs a shell-out.
     *
     * The TLS 1.3 question costs an `apachectl -t`, so it is asked only when
     * this host could actually emit the directive — Apache present with mod_ssl
     * loaded. `$stack` is the probe's own snapshot; pass null to ask regardless
     * (which is what `edge:status` wants, to report the real answer).
     */
    public static function detect(SystemProbe $probe, ?ServerStack $stack = null): self
    {
        $base = self::fromBanners($probe->nginxBanner(), $probe->apacheBanner());

        return new self(
            nginxLogDir:  $base->nginxLogDir,
            apacheLogDir: $base->apacheLogDir,
            nginxVersion: $base->nginxVersion,
            apacheTls13:  $stack === null || $stack->apacheHasModule('ssl')
                ? $probe->apacheSupportsTls13()
                : true,
        );
    }

    /**
     * Build from the servers' own `-V` banners — the whole resolution, minus the
     * shell-out, so it is testable against a recorded banner from any platform
     * instead of only against the machine the suite happens to run on.
     */
    public static function fromBanners(string $nginxBanner, string $apacheBanner): self
    {
        return new self(
            nginxLogDir:  self::resolveNginxLogDir($nginxBanner),
            apacheLogDir: self::resolveApacheLogDir($apacheBanner),
            nginxVersion: preg_match('#nginx/(\d+\.\d+\.\d+)#', $nginxBanner, $m) === 1 ? $m[1] : null,
        );
    }

    public function hasNginxLogs(): bool
    {
        return $this->nginxLogDir !== '';
    }

    public function hasApacheLogs(): bool
    {
        return $this->apacheLogDir !== '';
    }

    /** `<dir>/<site>.<kind>.log` — only ever called when the dir is known. */
    public function nginxLog(string $site, string $kind): string
    {
        return $this->nginxLogDir . '/' . $site . '.' . $kind . '.log';
    }

    /** `<dir>/<site>.<kind>.log` for Apache. */
    public function apacheLog(string $site, string $kind): string
    {
        return $this->apacheLogDir . '/' . $site . '.' . $kind . '.log';
    }

    /**
     * Does this nginx understand the standalone `http2 on;` directive?
     *
     * It arrived in 1.25.1. Before that HTTP/2 is a `listen … ssl http2`
     * parameter and `http2 on;` is an "unknown directive" — a HARD configtest
     * failure, not a warning. An UNKNOWN version answers true: we only downgrade
     * on positive evidence, because the legacy spelling is deprecated on every
     * modern build and would otherwise become the default forever.
     */
    public function supportsHttp2Directive(): bool
    {
        return $this->nginxVersion === null
            || version_compare($this->nginxVersion, '1.25.1', '>=');
    }

    // ── cheap, no-shell platform defaults (safe to call from config/edge.php) ──

    /** The machine's hosts file. */
    public static function defaultHostsFile(): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return rtrim((string) (getenv('SystemRoot') ?: 'C:\\Windows'), '\\')
                . '\\System32\\drivers\\etc\\hosts';
        }

        return '/etc/hosts';
    }

    /**
     * Where a generated certificate/key pair is expected to live. `/etc/ssl` is
     * the Linux answer; elsewhere (macOS, the BSDs) there is no `/etc/ssl/private`
     * at all, so the server's own config directory is the conventional home.
     */
    public static function defaultSslCert(): string
    {
        return (self::sslBase() ?? '/etc/ssl/certs') . '/hkm-edge.pem';
    }

    public static function defaultSslKey(): string
    {
        return (self::sslBase() ?? '/etc/ssl/private') . '/hkm-edge.key';
    }

    /**
     * The systemd unit directory, or '' when this host does not run systemd —
     * in which case `edge:service --write` demands an explicit target rather
     * than dropping a systemd unit somewhere that will never read it.
     */
    public static function defaultSystemdDir(): string
    {
        return is_dir('/etc/systemd/system') ? '/etc/systemd/system' : '';
    }

    /** The supervisor drop-in directory, or '' when supervisor is not installed. */
    public static function defaultSupervisorDir(): string
    {
        foreach (['/etc/supervisor/conf.d', '/etc/supervisord.d', '/opt/homebrew/etc/supervisor.d', '/usr/local/etc/supervisor.d'] as $dir) {
            if (is_dir($dir)) {
                return $dir;
            }
        }

        return '';
    }

    // ── resolution ────────────────────────────────────────────────────────────

    /**
     * One directory for BOTH halves of the pair, or null to keep the Linux
     * `/etc/ssl/{certs,private}` split.
     *
     * The split is only meaningful where `/etc/ssl/private` actually exists (it
     * is the mode-0700 directory that makes it safe). macOS has `/etc/ssl/certs`
     * but no `private`, so honouring half the layout would file the key and the
     * certificate in different places — the pair must stay together.
     */
    private static function sslBase(): ?string
    {
        if (is_dir('/etc/ssl/private')) {
            return null; // Linux layout: certs/ and private/ are both real
        }
        foreach (['/opt/homebrew/etc/nginx', '/usr/local/etc/nginx', '/etc/nginx'] as $dir) {
            if (is_dir($dir)) {
                return $dir . '/ssl';
            }
        }

        return null;
    }

    private static function resolveNginxLogDir(string $banner): string
    {
        $explicit = trim((string) edge_config('logs.nginx_dir', ''));
        if ($explicit !== '') {
            return rtrim($explicit, '/');
        }

        $compiled = self::compiledPath($banner, '#--error-log-path=(\S+)#');
        if ($compiled !== null && is_dir($dir = \dirname($compiled))) {
            return $dir;
        }

        return self::firstDir(['/var/log/nginx', '/opt/homebrew/var/log/nginx', '/usr/local/var/log/nginx']);
    }

    private static function resolveApacheLogDir(string $banner): string
    {
        $explicit = trim((string) edge_config('logs.apache_dir', ''));
        if ($explicit !== '') {
            return rtrim($explicit, '/');
        }

        // apachectl -V reports:  -D HTTPD_ROOT="/usr"
        //                        -D DEFAULT_ERRORLOG="logs/error_log"
        // The error log is often RELATIVE to HTTPD_ROOT, so it has to be joined
        // before it means anything (and on macOS the joined path does not exist,
        // which is exactly why the conventional list below still runs).
        if (preg_match('#DEFAULT_ERRORLOG="([^"]+)"#', $banner, $m) === 1) {
            $log = $m[1];
            if (!str_starts_with($log, '/') && preg_match('#HTTPD_ROOT="([^"]+)"#', $banner, $r) === 1) {
                $log = rtrim($r[1], '/') . '/' . $log;
            }
            if (str_starts_with($log, '/') && is_dir($dir = \dirname($log))) {
                return $dir;
            }
        }

        return self::firstDir(['/var/log/apache2', '/var/log/httpd', '/opt/homebrew/var/log/httpd', '/usr/local/var/log/httpd']);
    }

    /**
     * A compiled-in path from an `nginx -V` banner, absolutised against
     * `--prefix=` when it is relative (the vanilla source default is the
     * relative `logs/error.log`). Null when absent or not a real path
     * (`stderr`, `/dev/stdout`, `off`).
     */
    private static function compiledPath(string $banner, string $pattern): ?string
    {
        if (preg_match($pattern, $banner, $m) !== 1) {
            return null;
        }
        $path = $m[1];
        if (\in_array($path, ['stderr', 'off', '/dev/stdout', '/dev/stderr', '/dev/null'], true)) {
            return null;
        }
        if (!str_starts_with($path, '/')) {
            if (preg_match('#--prefix=(\S+)#', $banner, $p) !== 1) {
                return null;
            }
            $path = rtrim($p[1], '/') . '/' . $path;
        }

        return $path;
    }

    /** @param list<string> $candidates */
    private static function firstDir(array $candidates): string
    {
        foreach ($candidates as $dir) {
            if (is_dir($dir)) {
                return $dir;
            }
        }

        return '';
    }
}
