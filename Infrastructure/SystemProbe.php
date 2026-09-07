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
    /** Memoised `nginx -V` / `apachectl -V` output — host facts, read once per run. */
    private ?string $nginxBanner = null;
    private ?string $apacheBanner = null;
    private ?bool $apacheTls13 = null;

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

    /**
     * `nginx -V`, run at most ONCE per probe instance.
     *
     * Three callers need it (stream, brotli, version) and it is also the only
     * source for the compiled-in log path, so without the memo a single
     * `edge:apply` forked nginx four times to read the same banner. The probe is
     * constructed per command, so this caches a host fact for the life of one
     * run and never crosses a request.
     */
    public function nginxBanner(): string
    {
        return $this->nginxBanner ??= $this->run('nginx -V')[1];
    }

    /**
     * `apachectl -V`, memoised — the compiled-in ServerRoot and default error
     * log come from here. Empty when Apache is absent or the front-end is named
     * differently on this host.
     */
    public function apacheBanner(): string
    {
        if ($this->apacheBanner !== null) {
            return $this->apacheBanner;
        }
        foreach (['apache2ctl -V', 'apachectl -V', 'httpd -V'] as $cmd) {
            [$code, $out] = $this->run($cmd);
            if ($code === 0 && trim($out) !== '') {
                return $this->apacheBanner = $out;
            }
        }

        return $this->apacheBanner = '';
    }

    /**
     * Does THIS Apache accept `SSLProtocol … +TLSv1.3`?
     *
     * The token needs Apache 2.4.36+ built against OpenSSL 1.1.1+, and the
     * version alone cannot tell you: macOS ships a current 2.4.67 linked against
     * Apple's LibreSSL, which rejects it — as does any build on OpenSSL 1.0.2
     * (RHEL 7, Ubuntu 18.04). Rejecting it is a HARD configtest failure, so
     * every TLS mode of the Apache strategy failed on those hosts.
     *
     * Nothing Apache prints reports its SSL library, so we ASK it: a throwaway
     * config that includes the host's real one (which is what loads mod_ssl,
     * wherever it lives) and then states the directive. The verdict is read from
     * the message, not the exit code — the host's own config may be failing for
     * reasons of its own, and that is not this question.
     *
     * Unknown answers true: we only drop a security setting on positive
     * evidence, and a genuinely unsupported token is still caught by the
     * configtest Edge runs before reloading.
     */
    public function apacheSupportsTls13(): bool
    {
        if ($this->apacheTls13 !== null) {
            return $this->apacheTls13;
        }
        if (trim($this->apacheBanner()) === '') {
            return $this->apacheTls13 = true; // no Apache to ask
        }

        $config = $this->apacheConfigFile();
        if ($config === null) {
            return $this->apacheTls13 = true;
        }

        $probe = sys_get_temp_dir() . '/hkm-edge-tls13-' . bin2hex(random_bytes(4)) . '.conf';
        if (@file_put_contents($probe, "Include {$config}
SSLProtocol -all +TLSv1.3
") === false) {
            return $this->apacheTls13 = true;
        }

        [, $out] = $this->run($this->apacheBinary() . ' -t -f ' . escapeshellarg($probe));
        @unlink($probe);

        // "Illegal protocol 'TLSv1.3'" is the exact refusal; anything else
        // (including a broken host config) leaves the answer at "supported".
        return $this->apacheTls13 = !(str_contains($out, 'Illegal protocol') && str_contains($out, 'TLSv1.3'));
    }

    /** The compiled-in main config file, from `apachectl -V`. */
    private function apacheConfigFile(): ?string
    {
        if (preg_match('#SERVER_CONFIG_FILE="([^"]+)"#', $this->apacheBanner(), $m) !== 1) {
            return null;
        }
        $file = $m[1];
        if (!str_starts_with($file, '/') && preg_match('#HTTPD_ROOT="([^"]+)"#', $this->apacheBanner(), $r) === 1) {
            $file = rtrim($r[1], '/') . '/' . $file;
        }

        return is_file($file) ? $file : null;
    }

    /** The Apache front-end this host answers to, for a one-off command. */
    private function apacheBinary(): string
    {
        foreach (['apache2ctl', 'apachectl', 'httpd'] as $bin) {
            if ($this->which($bin)) {
                return $bin;
            }
        }

        return 'httpd';
    }

    /**
     * The installed nginx version ("1.24.0"), or null when it cannot be read.
     *
     * This decides which HTTP/2 spelling the renderer emits: `http2 on;` is a
     * 1.25.1+ directive, and on anything older it is a HARD `nginx -t` failure —
     * which is every current LTS (Ubuntu 22.04 ships 1.18, Debian 12 ships 1.22,
     * RHEL 9 ships 1.20). Null means "unknown": the caller keeps the modern
     * spelling rather than guessing a downgrade from no evidence.
     */
    public function nginxVersion(): ?string
    {
        return preg_match('#nginx/(\d+\.\d+\.\d+)#', $this->nginxBanner(), $m) === 1 ? $m[1] : null;
    }

    /** The PHP version running THIS command, e.g. "8.4". */
    public function phpCliVersion(): string
    {
        return PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    }

    /**
     * Directories a PHP-FPM pool socket is created in, most-conventional first.
     *
     * The first four are the Linux distro layouts; the Homebrew prefixes cover
     * macOS (Apple Silicon and Intel) and `/var/run` covers the BSDs. Probing a
     * directory that does not exist costs one failed stat, so the list is cheap
     * to extend and the ordering — not the membership — is what decides the
     * answer on a host that has several.
     */
    private const FPM_RUN_DIRS = [
        '/run/php',
        '/var/run/php',
        '/run/php-fpm',
        '/var/run/php-fpm',
        '/opt/homebrew/var/run',
        '/usr/local/var/run',
        '/var/run',
    ];

    /**
     * Resolve the PHP-FPM upstream that matches the CLI PHP version running the
     * command, so a multi-PHP host binds the vhost to the RIGHT pool:
     *   1. the versioned socket for the CLI version (Debian/Ubuntu naming),
     *   2. any versioned socket present — the exact version, else the newest,
     *   3. a generic/unversioned socket (RHEL, Homebrew, BSD, custom),
     *   4. a TCP fallback (127.0.0.1:9000 — containers, and Homebrew's default).
     */
    public function phpFpmSocket(): string
    {
        $ver = $this->phpCliVersion();

        foreach (self::FPM_RUN_DIRS as $dir) {
            if (@file_exists($sock = "{$dir}/php{$ver}-fpm.sock")) {
                return "unix:{$sock}";
            }
        }

        $socks = [];
        foreach (self::FPM_RUN_DIRS as $dir) {
            $socks = array_merge($socks, glob("{$dir}/php*-fpm.sock") ?: []);
        }
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

        foreach (self::FPM_RUN_DIRS as $dir) {
            foreach (["{$dir}/www.sock", "{$dir}/php-fpm.sock"] as $sock) {
                if (@file_exists($sock)) {
                    return "unix:{$sock}";
                }
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
        // `pgrep` is the ONLY way active() can answer on a host without systemd
        // — macOS, every container, Alpine, the BSDs. Without it the fallback
        // was refused with exit 126, so every such host reported "no active web
        // server" and Edge did nothing at all there.
        'pgrep',
        // Windows has no `command -v`, no systemd and no pgrep, so every probe
        // below answered "not installed / not running" there and the strategy was
        // permanently `none`. `where` replaces `command -v`; `sc` and `tasklist`
        // replace `systemctl` and `pgrep`; `net` starts a service.
        'where', 'sc', 'tasklist', 'net',
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
        [$code] = $this->run(self::whichCommand($binary));

        return $code === 0;
    }

    /**
     * How this host asks "is this binary on PATH".
     *
     * `command -v` is a POSIX SHELL builtin; cmd.exe has no such thing, so on
     * Windows the probe answered "not installed" for every server the machine
     * actually had. `where` is the native equivalent and sets the same exit code.
     */
    public static function whichCommand(string $binary, string $osFamily = PHP_OS_FAMILY): string
    {
        return $osFamily === 'Windows'
            ? 'where ' . escapeshellarg($binary)
            : 'command -v ' . escapeshellarg($binary);
    }

    /**
     * How to START a server this host has INSTALLED but is not running, phrased
     * in the words of this machine's own service manager.
     *
     * WHY THIS EXISTS
     * ---------------
     * `strategy()` answers `none` when no web server is ACTIVE, and Edge then
     * has nothing to write: no vhost, no reload target. On a systemd box that
     * state is rare, because the package manager starts and enables the service
     * for you. On macOS under Homebrew it is the DEFAULT — `brew install nginx`
     * installs it stopped — so the honest report "no active web server" is, to a
     * Mac user, indistinguishable from "this tool does not work here".
     *
     * The difference between those two readings is one command, and this is the
     * only object that knows which command it is.
     *
     * Nothing here shells out to START anything. Choosing to run a web server as
     * root is the operator's call, and a tool that quietly did it on their behalf
     * would be making a different decision than the one they asked for.
     *
     * @return list<string> Lines to show the operator; empty when there is
     *                      nothing useful to say (no server installed at all).
     */
    public function startHints(ServerStack $stack): array
    {
        $stopped = [];
        if ($stack->nginxInstalled && !$stack->nginxActive) {
            $stopped[] = 'nginx';
        }
        if ($stack->apacheInstalled && !$stack->apacheActive) {
            // Debian calls the unit apache2, everyone else httpd — and Homebrew's
            // formula is httpd too. Picking by the control binary that is present
            // gets RHEL and Homebrew right without asking what OS this is.
            $stopped[] = $this->which('apache2ctl') ? 'apache2' : 'httpd';
        }

        return self::hintsFor(
            $stopped,
            osFamily:   PHP_OS_FAMILY,
            hasBrew:    $this->which('brew'),
            hasSystemd: $this->which('systemctl'),
        );
    }

    /**
     * The whole decision, minus the shell-out — so every platform's wording can
     * be tested from any machine, the way HostPaths::fromBanners() is.
     *
     * @param  list<string> $stopped  Service names that are installed but stopped.
     * @param  string        $osFamily PHP_OS_FAMILY — 'Darwin', 'Windows', 'Linux', …
     * @return list<string>
     */
    public static function hintsFor(array $stopped, string $osFamily, bool $hasBrew, bool $hasSystemd): array
    {
        if ($stopped === []) {
            return [];
        }

        if ($osFamily === 'Windows') {
            $lines = ['Start the server, then re-run this command:'];
            foreach ($stopped as $service) {
                $lines[] = '  net start ' . $service;
            }
            // Worth saying plainly: on Windows neither server is a service unless
            // someone installed it as one (nssm, winsw, or Apache's own
            // installer). `net start` fails with "service name is invalid" for a
            // plain nginx.exe, which reads as a broken hint rather than the wrong
            // start method.
            $lines[] = '  (only if it was installed AS a service — otherwise start';
            $lines[] = '   nginx.exe / httpd.exe from its install directory)';

            return $lines;
        }

        // Homebrew before systemd: on a Mac that has it, systemctl does not exist
        // and `brew services` is the only manager that survives a reboot.
        if ($osFamily === 'Darwin' && $hasBrew) {
            $lines = ['This Mac uses Homebrew. Start the server, then re-run this command:'];
            foreach ($stopped as $service) {
                $lines[] = '  sudo brew services start ' . $service;
            }
            // :80/:443 are privileged, so the service has to be root-owned.
            // `brew services start` WITHOUT sudo registers a per-user LaunchAgent
            // that cannot bind either port — and that fails later, at startup,
            // where it is much harder to connect back to this moment.
            $lines[] = '  (sudo is needed to bind :80/:443 — without it Homebrew';
            $lines[] = '   registers a user agent that cannot listen on them)';

            return $lines;
        }

        if ($hasSystemd) {
            $lines = ['Start the server, then re-run this command:'];
            foreach ($stopped as $service) {
                $lines[] = '  sudo systemctl start ' . $service;
            }

            return $lines;
        }

        return [
            'Installed but not running: ' . implode(', ', $stopped) . '.',
            "Start it with this host's service manager, then re-run this command.",
        ];
    }

    /**
     * Is a service active? Prefer systemd; fall back to a process match so it
     * still works on non-systemd hosts / inside containers.
     */
    private function active(string $service): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return $this->activeOnWindows($service);
        }

        [$code, $out] = $this->run('systemctl is-active ' . escapeshellarg($service));
        if ($code === 0 && trim($out) === 'active') {
            return true;
        }

        [$pcode] = $this->run('pgrep -x ' . escapeshellarg($service));
        if ($pcode === 0) {
            return true;
        }

        // macOS cannot answer with -x, and this is the bug that made Edge look
        // broken there. nginx REWRITES its own process title, so the accounting
        // name BSD pgrep matches against is the whole string
        //
        //     nginx: master process /opt/homebrew/opt/nginx/bin/nginx -g daemon off;
        //
        // and `pgrep -x nginx` — which demands an EXACT match — finds nothing
        // while nginx is plainly serving. Linux is unaffected: there the title
        // rewrite lands in cmdline and /proc/<pid>/comm stays `nginx`, so -x
        // matches and this second call never runs.
        //
        // Dropping -x makes it a substring match on the process NAME. That is
        // deliberately not `pgrep -f`, which matches the whole command line and
        // would count `tail -f /var/log/nginx/error.log` as a running nginx.
        [$loose] = $this->run('pgrep ' . escapeshellarg($service));

        return $loose === 0;
    }

    /**
     * The Windows answer to the same question.
     *
     * Both probes read the OUTPUT rather than the exit code, which is the whole
     * subtlety: `tasklist` exits 0 even when it matched nothing (it prints
     * "INFO: No tasks are running…"), and `sc query` exits 0 for a service that
     * exists but is STOPPED. Trusting either exit code would report every
     * installed server as running.
     *
     * `sc` is asked first because a service is the only form that survives a
     * reboot; the process check then catches the common case of nginx.exe
     * started by hand from its install directory, which is how nginx usually
     * runs on Windows.
     *
     * No pipes: run() refuses any command containing shell metacharacters, so
     * the filtering happens here in PHP rather than through `| find`.
     */
    private function activeOnWindows(string $service): bool
    {
        [, $sc] = $this->run('sc query ' . escapeshellarg($service));
        if (stripos($sc, 'RUNNING') !== false) {
            return true;
        }

        [, $tasks] = $this->run('tasklist /NH /FI ' . escapeshellarg('IMAGENAME eq ' . $service . '.exe'));

        return stripos($tasks, $service . '.exe') !== false;
    }

    /** Does the installed nginx support the stream (L4) module? */
    private function nginxHasStream(): bool
    {
        if (str_contains($this->nginxBanner(), '--with-stream')) {
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
        if (str_contains($this->nginxBanner(), 'brotli')) {
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
