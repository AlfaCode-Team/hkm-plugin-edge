<?php

declare(strict_types=1);

namespace Plugins\Edge\Infrastructure;

use Plugins\Edge\Domain\ServerStack;

/**
 * Talks to the operating system (the "vendor" here is the host itself): detects
 * which web servers are installed/active and whether nginx has the stream
 * module, and runs validate/reload commands. All shell access is funnelled
 * through run() so nothing else in the plugin shells out directly.
 */
final class SystemProbe
{
    /**
     * Probe the host and build an immutable ServerStack snapshot.
     *
     * When the operator has PINNED a single server (`--nginx-only` /
     * `--apache-only`, or EDGE_FORCE_STRATEGY) the other one is irrelevant to the
     * outcome, so we do not probe it at all: it reports absent across the board
     * and its shell-outs (`apachectl -M`, `nginx -V`, `nginx -T`, `systemctl`) are
     * skipped. That keeps a pinned run fast, and — more importantly — keeps the
     * reported stack HONEST: `edge:status --nginx-only` must not claim Apache is
     * part of a plan that will never touch it.
     */
    public function detect(?bool $nginxOnly = null, ?bool $apacheOnly = null): ServerStack
    {
        $probeNginx  = $apacheOnly !== true;
        $probeApache = $nginxOnly !== true;

        $nginxInstalled  = $probeNginx && $this->which('nginx');
        $apacheInstalled = $probeApache
            && ($this->which('apache2') || $this->which('httpd') || $this->which('apachectl'));

        return new ServerStack(
            nginxInstalled:  $nginxInstalled,
            nginxActive:     $probeNginx && $this->active('nginx'),
            nginxHasStream:  $nginxInstalled && $this->nginxHasStream(),
            apacheInstalled: $apacheInstalled,
            apacheActive:    $probeApache && ($this->active('apache2') || $this->active('httpd')),
            nginxHasBrotli:  $nginxInstalled && $this->nginxHasBrotli(),
            apacheModules:   $apacheInstalled ? $this->apacheModules() : [],
            nginxHasStreamConfig: $nginxInstalled && $this->nginxStreamConfigExists((string) edge_config('paths.stream', '')),
        );
    }

    /**
     * Does the RUNNING nginx already declare an SNI stream splitter (a `stream {}`
     * block using `ssl_preread`) in a config file OTHER than the one Edge manages?
     */
    private function nginxStreamConfigExists(string $ownPath): bool
    {
        return $this->nginxStreamConfigFile($ownPath) !== null;
    }

    /**
     * The ON-DISK path of the config file that holds the RUNNING nginx's SNI
     * stream splitter (the `map $ssl_preread_server_name … { … }`), or null when
     * none exists — so Edge can UPDATE that file's map in place instead of writing
     * a second, conflicting splitter.
     *
     * `nginx -T` dumps the full, resolved config, prefixing each file with a
     * `# configuration file <path>:` marker. We walk it file-by-file, skip Edge's
     * own managed file (so re-runs never match themselves), and return the first
     * OTHER file that declares the ssl_preread map. `ssl_preread` and that map only
     * ever appear inside a stream server, so their presence is a reliable signal.
     */
    public function nginxStreamConfigFile(string $ownPath): ?string
    {
        [$code, $dump] = $this->run('nginx -T');
        if ($code !== 0 || trim($dump) === '') {
            return null;
        }

        $own = ($ownPath !== '' ? (realpath($ownPath) ?: $ownPath) : '');

        $current = '';
        $byFile  = [];
        foreach (explode("\n", $dump) as $line) {
            if (preg_match('/^#\s*configuration file\s+(.+):\s*$/', $line, $m)) {
                $current = trim($m[1]);
                $byFile[$current] ??= '';
                continue;
            }
            if ($current !== '') {
                $byFile[$current] .= $line . "\n";
            }
        }

        foreach ($byFile as $path => $body) {
            if ($own !== '' && (realpath($path) ?: $path) === $own) {
                continue; // Edge's own managed file — not a pre-existing config
            }
            // The map is the splitter's routing table; ssl_preread confirms it's a
            // real SNI stream server and not an incidental mention.
            if (str_contains($body, 'ssl_preread') && preg_match('/map\s+\$ssl_preread_server_name\s+\$\w+\s*\{/', $body)) {
                // Only files that still exist on disk can be updated in place.
                if (is_file($path) && is_writable($path)) {
                    return $path;
                }
                if (is_file($path)) {
                    return $path; // exists but not writable — caller reports "need sudo"
                }
            }
        }

        return null;
    }

    /**
     * The Apache modules currently LOADED, as short names (no `_module` suffix),
     * parsed from `apachectl -M`. Empty list = couldn't probe (caller treats
     * that as "unknown", not "absent"). Tries the common front-ends in turn.
     */
    public function apacheModules(): array
    {
        foreach (['apache2ctl -M', 'apachectl -M', 'httpd -M'] as $cmd) {
            [$code, $out] = $this->run($cmd);
            if ($code !== 0 || trim($out) === '') {
                continue;
            }
            // Lines look like "  headers_module (shared)"; grab the module name.
            preg_match_all('/^\s*(\w+)_module\b/m', $out, $m);
            if ($m[1] !== []) {
                return array_values(array_unique($m[1]));
            }
        }

        return [];
    }

    /** The PHP version running THIS command, e.g. "8.4". */
    public function phpCliVersion(): string
    {
        return PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    }

    /**
     * Resolve the PHP-FPM upstream that matches the CLI PHP version running the
     * command, so a multi-PHP host binds the vhost to the RIGHT pool:
     *   1. the versioned socket for the CLI version (Debian/Ubuntu naming),
     *   2. any versioned socket present — the exact version, else the newest,
     *   3. a generic/unversioned socket (RHEL, custom),
     *   4. a TCP fallback (127.0.0.1:9000, common in containers).
     */
    public function phpFpmSocket(): string
    {
        $ver = $this->phpCliVersion();

        foreach (["/run/php/php{$ver}-fpm.sock", "/var/run/php/php{$ver}-fpm.sock"] as $sock) {
            if (@file_exists($sock)) {
                return "unix:{$sock}";
            }
        }

        $socks = array_merge(glob('/run/php/php*-fpm.sock') ?: [], glob('/var/run/php/php*-fpm.sock') ?: []);
        if ($socks !== []) {
            // exact CLI version wins; otherwise the newest available pool.
            usort($socks, fn (string $a, string $b): int => version_compare($this->sockVersion($b), $this->sockVersion($a)));
            foreach ($socks as $s) {
                if ($this->sockVersion($s) === $ver) {
                    return "unix:{$s}";
                }
            }
            return "unix:{$socks[0]}";
        }

        foreach (['/run/php-fpm/www.sock', '/var/run/php-fpm/www.sock', '/run/php/php-fpm.sock'] as $sock) {
            if (@file_exists($sock)) {
                return "unix:{$sock}";
            }
        }

        return '127.0.0.1:9000';
    }

    /** Which php*-fpm services systemd reports as active (best-effort, for status). */
    public function phpFpmActive(): array
    {
        [$code, $out] = $this->run("systemctl list-units --type=service --state=active --no-legend 'php*-fpm*.service'");
        if ($code !== 0 || trim($out) === '') {
            return [];
        }
        $names = [];
        foreach (explode("\n", trim($out)) as $line) {
            if (preg_match('/(php[0-9.]*-fpm[^\s]*)\.service/', $line, $m)) {
                $names[] = $m[1];
            }
        }

        return array_values(array_unique($names));
    }

    private function sockVersion(string $path): string
    {
        return preg_match('/php(\d+\.\d+)-fpm\.sock$/', $path, $m) ? $m[1] : '0';
    }

    /**
     * Binaries this probe is permitted to invoke.
     *
     * The commands come from configuration (EDGE_* env), and the reload path
     * typically runs as ROOT. Env is a weaker trust boundary than code — a
     * compromised .env, a bad deploy template or an operator typo previously
     * meant arbitrary root command execution, because the whole string went to
     * exec() with full shell interpretation.
     *
     * Extend with EDGE_ALLOWED_BINARIES (comma-separated) rather than editing
     * this list, so a distro-specific binary needs no code change.
     */
    private const ALLOWED_BINARIES = [
        'nginx', 'apache2ctl', 'apachectl', 'httpd',
        'systemctl', 'service', 'rc-service', 'brew',
        'command', 'test', 'true',
    ];

    /**
     * Run a command; returns [exitCode, combinedOutput].
     *
     * The command still runs through a shell — `which()` depends on the
     * `command -v` builtin, and the configured reload strings legitimately use
     * `sudo` — but the EFFECTIVE binary is checked against an allow-list first,
     * so a hostile configuration value cannot turn this into arbitrary root
     * execution.
     */
    public function run(string $command): array
    {
        $binary = self::effectiveBinary($command);

        if ($binary === null || !self::isAllowedBinary($binary)) {
            return [
                126, // shell convention: found but not executable/permitted
                sprintf(
                    'Refusing to run [%s]: %s is not an allowed binary. '
                    . 'Add it to EDGE_ALLOWED_BINARIES if this is intended.',
                    $command,
                    $binary ?? '(none)',
                ),
            ];
        }

        $output = [];
        $code   = 0;
        @exec($command . ' 2>&1', $output, $code);

        return [$code, implode("\n", $output)];
    }

    /**
     * The binary a command will actually invoke, skipping a leading `sudo` and
     * any of its flags, and reducing an absolute path to its basename.
     *
     * Also rejects a command containing shell metacharacters that would let a
     * second command ride along ( ; | & ` $( ), since only the FIRST binary is
     * being validated.
     */
    private static function effectiveBinary(string $command): ?string
    {
        $command = trim($command);

        if ($command === '' || preg_match('/[;&|`]|\$\(/', $command) === 1) {
            return null;
        }

        $tokens = preg_split('/\s+/', $command) ?: [];

        foreach ($tokens as $token) {
            if ($token === 'sudo' || str_starts_with($token, '-')) {
                continue; // sudo itself, and its flags, are not the target
            }
            if (str_contains($token, '=')) {
                continue; // FOO=bar prefix assignment
            }

            return basename($token);
        }

        return null;
    }

    private static function isAllowedBinary(string $binary): bool
    {
        $extra = \function_exists('env') ? (string) (env('EDGE_ALLOWED_BINARIES') ?? '') : '';

        $allowed = self::ALLOWED_BINARIES;
        foreach (explode(',', $extra) as $name) {
            $name = trim($name);
            if ($name !== '') {
                $allowed[] = $name;
            }
        }

        return \in_array($binary, $allowed, true);
    }

    private function which(string $binary): bool
    {
        [$code] = $this->run('command -v ' . escapeshellarg($binary));

        return $code === 0;
    }

    /**
     * Is a service active? Prefer systemd; fall back to a process match so it
     * still works on non-systemd hosts / inside containers.
     */
    private function active(string $service): bool
    {
        [$code, $out] = $this->run('systemctl is-active ' . escapeshellarg($service));
        if ($code === 0 && trim($out) === 'active') {
            return true;
        }

        [$pcode] = $this->run('pgrep -x ' . escapeshellarg($service));

        return $pcode === 0;
    }

    /** Does the installed nginx support the stream (L4) module? */
    private function nginxHasStream(): bool
    {
        [, $banner] = $this->run('nginx -V');
        if (str_contains($banner, '--with-stream')) {
            return true;
        }

        // Dynamic module shipped separately (Debian/RHEL common paths).
        foreach ([
            '/usr/lib/nginx/modules/ngx_stream_module.so',
            '/usr/lib64/nginx/modules/ngx_stream_module.so',
            '/etc/nginx/modules/ngx_stream_module.so',
        ] as $path) {
            if (is_file($path)) {
                return true;
            }
        }

        return false;
    }

    /** Was the installed nginx built with (or shipped) the ngx_brotli module? */
    private function nginxHasBrotli(): bool
    {
        [, $banner] = $this->run('nginx -V');
        if (str_contains($banner, 'brotli')) {
            return true;
        }

        foreach ([
            '/usr/lib/nginx/modules/ngx_http_brotli_filter_module.so',
            '/usr/lib64/nginx/modules/ngx_http_brotli_filter_module.so',
            '/etc/nginx/modules/ngx_http_brotli_filter_module.so',
        ] as $path) {
            if (is_file($path)) {
                return true;
            }
        }

        return false;
    }
}
