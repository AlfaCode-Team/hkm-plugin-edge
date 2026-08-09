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
use Plugins\Edge\Domain\SwooleOptions;
use Plugins\Edge\Domain\TlsConfig;
use Plugins\Edge\Domain\TlsMode;
use Plugins\Edge\Infrastructure\ConfigRenderer;

/**
 * `limit_req` / `limit_conn` belong INSIDE the location that runs the application
 * — the PHP front controller (FPM) or the proxy location (OpenSwoole) — and
 * nowhere else.
 *
 * At server scope the limit also counts every static asset, so one page load
 * pulling 30 files burns 31 tokens against the zone: a real visitor gets 429s on
 * CSS/JS long before the dynamic request that actually needed protecting is
 * throttled. These tests pin the placement so it cannot drift back.
 *
 * Each test runs in a SEPARATE PROCESS: edge_config_fallback() caches the parsed
 * config in a static, so EDGE_HTTP_PRELUDE has to be set before the very first
 * edge_config() call of the process.
 */
final class NginxRateLimitPlacementTest extends TestCase
{
    /** Enable the http prelude (which gates the rate limit) for this process. */
    private function enableRateLimit(): void
    {
        $_ENV['EDGE_HTTP_PRELUDE'] = '1';
        $_SERVER['EDGE_HTTP_PRELUDE'] = '1';
    }

    private function stack(): ServerStack
    {
        // nginx-only, no brotli → deterministic output.
        return new ServerStack(true, true, false, false, false, false, [], false);
    }

    private function render(Site $site): string
    {
        [, $body] = (new ConfigRenderer())->render(
            Strategy::NginxOnly,
            [$site],
            new TlsConfig(TlsMode::None, '/etc/ssl/certs/x.pem', '/etc/ssl/private/x.key'),
            $this->stack(),
            CacheProfile::Production,
        );

        return $body;
    }

    private function fpmSite(): Site
    {
        return new Site(
            name: 'hkmstd',
            docroot: '/srv/hkmstd/app/public',
            publicDomains: ['hkmstd.com'],
            localDomains: [],
            model: ServeModel::Fpm,
            upstream: 'unix:/run/php/php8.4-fpm.sock',
            env: ['APP_ENV' => 'production'],
            root: '/srv/hkmstd',
        );
    }

    private function swooleSite(): Site
    {
        return new Site(
            name: 'hkmswoole',
            docroot: '/srv/hkmswoole/app/public',
            publicDomains: ['hkmswoole.com'],
            localDomains: [],
            model: ServeModel::Swoole,
            upstream: '127.0.0.1:9501',
            env: ['APP_ENV' => 'production'],
            swoole: new SwooleOptions(host: '127.0.0.1', port: 9501),
            root: '/srv/hkmswoole',
        );
    }

    /** The body of the block opened by $opener, up to its closing brace. */
    private function block(string $config, string $opener): string
    {
        $start = strpos($config, $opener);
        self::assertNotFalse($start, "expected a `{$opener}` block in the generated config");

        $depth = 0;
        $len   = strlen($config);
        for ($i = $start + strlen($opener) - 1; $i < $len; $i++) {
            if ($config[$i] === '{') {
                $depth++;
            } elseif ($config[$i] === '}' && --$depth === 0) {
                return substr($config, $start, $i - $start + 1);
            }
        }

        self::fail("unbalanced braces after `{$opener}`");
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_fpm_limits_live_in_the_php_front_controller_only(): void
    {
        $this->enableRateLimit();
        $config = $this->render($this->fpmSite());

        $php = $this->block($config, 'location = /index.php {');
        self::assertStringContainsString('limit_req zone=general', $php);
        self::assertStringContainsString('limit_conn perip', $php);

        // Exactly one of each in the whole vhost — no server-scope duplicate.
        self::assertSame(1, substr_count($config, 'limit_req zone='));
        self::assertSame(1, substr_count($config, 'limit_conn perip'));

        // Server scope is 4-space indented; the location body is 8.
        self::assertStringNotContainsString("\n    limit_req ", $config);
        self::assertStringNotContainsString("\n    limit_conn ", $config);

        // Static assets must never be throttled.
        self::assertStringNotContainsString('limit_req', $this->block($config, 'location ~* \.('));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_swoole_limits_live_in_the_proxy_location_only(): void
    {
        $this->enableRateLimit();
        $config = $this->render($this->swooleSite());

        $proxy = $this->block($config, 'location / {');
        self::assertStringContainsString('limit_req zone=general', $proxy);
        self::assertStringContainsString('limit_conn perip', $proxy);

        self::assertSame(1, substr_count($config, 'limit_req zone='));
        self::assertStringNotContainsString("\n    limit_req ", $config);
        self::assertStringNotContainsString("\n    limit_conn ", $config);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_no_limits_at_all_when_the_prelude_is_off(): void
    {
        // The zones are declared BY the prelude, so emitting limit_req without it
        // would reference an undeclared zone and fail `nginx -t`.
        $_ENV['EDGE_HTTP_PRELUDE'] = '0';
        $_SERVER['EDGE_HTTP_PRELUDE'] = '0';

        $config = $this->render($this->fpmSite());

        self::assertStringNotContainsString('limit_req', $config);
        self::assertStringNotContainsString('limit_conn', $config);
    }
}
