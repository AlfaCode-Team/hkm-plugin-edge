<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Edge;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Plugins\Edge\Domain\CacheProfile;
use Plugins\Edge\Domain\ServeModel;
use Plugins\Edge\Domain\ServerStack;
use Plugins\Edge\Domain\Site;
use Plugins\Edge\Domain\Strategy;
use Plugins\Edge\Domain\TlsConfig;
use Plugins\Edge\Domain\TlsMode;
use Plugins\Edge\Infrastructure\ConfigRenderer;
use Plugins\Edge\Infrastructure\HostPaths;

/**
 * What the renderer emits for a host that is NOT Debian.
 *
 * Both behaviours here were previously hard-coded literals, and both produce a
 * config the server refuses to load when the literal is wrong: a missing
 * `access_log` directory stops nginx outright, and `http2 on;` is an unknown
 * directive on every nginx older than 1.25.1.
 */
final class HostPortabilityTest extends TestCase
{
    private function site(): Site
    {
        return new Site(
            name: 'port',
            docroot: '/srv/port/app/public',
            publicDomains: ['port.example'],
            localDomains: [],
            model: ServeModel::Fpm,
            upstream: 'unix:/run/php/php8.4-fpm.sock',
            env: ['APP_ENV' => 'production'],
            root: '/srv/port',
        );
    }

    private function render(HostPaths $paths, Strategy $strategy = Strategy::NginxOnly, TlsMode $mode = TlsMode::Ssl): string
    {
        $stack = $strategy === Strategy::ApacheOnly
            ? new ServerStack(false, false, false, true, true, false, ['headers', 'ssl'], false)
            : new ServerStack(true, true, false, false, false, false, [], false);

        [, $body] = (new ConfigRenderer($paths))->render(
            $strategy,
            [$this->site()],
            new TlsConfig($mode, '/tmp/x.pem', '/tmp/x.key'),
            $stack,
            CacheProfile::Production,
        );

        return $body;
    }

    // ── log directories ───────────────────────────────────────────────────────

    public function test_logs_go_to_the_hosts_own_directory(): void
    {
        // Homebrew nginx logs under its prefix; the Debian path does not exist
        // there at all.
        $out = $this->render(new HostPaths(nginxLogDir: '/opt/homebrew/var/log/nginx'));

        self::assertStringContainsString('access_log /opt/homebrew/var/log/nginx/port.access.log', $out);
        self::assertStringContainsString('error_log  /opt/homebrew/var/log/nginx/port.error.log', $out);
        // (the Homebrew path CONTAINS "/var/log/nginx", so match the directive)
        self::assertStringNotContainsString('access_log /var/log/nginx/', $out);
    }

    public function test_no_log_directory_means_no_log_directives(): void
    {
        // Emitting a path we could not find would be a config nginx REFUSES to
        // load; falling back to its global log is the degradation that keeps the
        // vhost serving.
        $out = $this->render(new HostPaths(nginxLogDir: ''));

        self::assertStringNotContainsString('access_log /', $out);
        self::assertStringContainsString('# Per-site logs omitted', $out);
        self::assertStringContainsString('EDGE_NGINX_LOG_DIR', $out);
    }

    public function test_apache_logs_follow_the_hosts_directory(): void
    {
        // RHEL/Fedora use /var/log/httpd, not Debian's /var/log/apache2.
        $out = $this->render(new HostPaths(apacheLogDir: '/var/log/httpd'), Strategy::ApacheOnly);

        self::assertStringContainsString('ErrorLog  /var/log/httpd/port.error.log', $out);
        self::assertStringContainsString('CustomLog /var/log/httpd/port.access.log combined', $out);
    }

    public function test_apache_omits_logs_when_no_directory_is_known(): void
    {
        $out = $this->render(new HostPaths(apacheLogDir: ''), Strategy::ApacheOnly);

        self::assertStringNotContainsString('ErrorLog', $out);
        self::assertStringContainsString('EDGE_APACHE_LOG_DIR', $out);
    }

    // ── the HTTP/2 spelling ───────────────────────────────────────────────────

    public function test_modern_nginx_gets_the_http2_directive(): void
    {
        $out = $this->render(new HostPaths(nginxVersion: '1.27.4'));

        self::assertStringContainsString("\n    http2 on;", $out);
        self::assertStringContainsString('listen 443 ssl;', $out);
        self::assertStringNotContainsString('ssl http2;', $out);
    }

    public function test_pre_1_25_nginx_gets_the_listen_parameter_instead(): void
    {
        // Debian 12 ships 1.22: `http2 on;` there is an unknown directive and
        // `nginx -t` fails, which on an apply means the reload is refused.
        $out = $this->render(new HostPaths(nginxVersion: '1.22.1'));

        self::assertStringNotContainsString('http2 on;', $out);
        self::assertStringContainsString('listen 443 ssl http2;', $out);
        self::assertStringContainsString('listen [::]:443 ssl http2;', $out);
    }

    public function test_plain_http_never_mentions_http2(): void
    {
        $out = $this->render(new HostPaths(nginxVersion: '1.22.1'), mode: TlsMode::None);

        self::assertStringNotContainsString('http2', $out);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_edge_http2_off_drops_it_entirely(): void
    {
        $_ENV['EDGE_HTTP2'] = $_SERVER['EDGE_HTTP2'] = 'off';

        $out = $this->render(new HostPaths(nginxVersion: '1.27.4'));

        self::assertStringNotContainsString('http2', $out);
        self::assertStringContainsString('listen 443 ssl;', $out);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_edge_http2_listen_pins_the_legacy_spelling(): void
    {
        // For generating on a build host whose nginx is newer than the one that
        // will load the file.
        $_ENV['EDGE_HTTP2'] = $_SERVER['EDGE_HTTP2'] = 'listen';

        $out = $this->render(new HostPaths(nginxVersion: '1.27.4'));

        self::assertStringContainsString('listen 443 ssl http2;', $out);
        self::assertStringNotContainsString('http2 on;', $out);
    }
}
