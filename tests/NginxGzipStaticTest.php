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

/**
 * `gzip_static` serves the `.gz` siblings Vite ships instead of recompressing
 * every bundle per request. It follows compression and the nginx build.
 */
final class NginxGzipStaticTest extends TestCase
{
    private function render(bool $brotli, bool $gzipStatic): string
    {
        $site = new Site(
            name: 'gz',
            docroot: '/srv/gz/app/public',
            publicDomains: ['gz.example'],
            localDomains: [],
            model: ServeModel::Fpm,
            upstream: 'unix:/run/php/php8.4-fpm.sock',
            env: ['APP_ENV' => 'production'],
            root: '/srv/gz',
        );
        $stack = new ServerStack(true, true, false, false, false, $brotli, [], false, nginxHasGzipStatic: $gzipStatic);
        $tls   = new TlsConfig(TlsMode::None, '', '');

        [, $body] = (new ConfigRenderer())->render(Strategy::NginxOnly, [$site], $tls, $stack, CacheProfile::Production);

        return $body;
    }

    public function test_gzip_mode_emits_gzip_static_when_the_module_is_built_in(): void
    {
        $out = $this->render(false, true);

        self::assertStringContainsString("    gzip_types", $out);
        self::assertSame(1, substr_count($out, 'gzip_static on;'));
        self::assertStringNotContainsString('brotli_static', $out);
    }

    public function test_brotli_mode_emits_gzip_static_after_the_gzip_fallback(): void
    {
        $out = $this->render(true, true);

        self::assertStringContainsString('brotli on;', $out);
        self::assertSame(1, substr_count($out, 'gzip_static on;'));
        self::assertGreaterThan(strpos($out, 'gzip_types'), strpos($out, 'gzip_static on;'));
    }

    public function test_nothing_is_emitted_when_the_module_is_absent_or_unknown(): void
    {
        self::assertStringNotContainsString('gzip_static', $this->render(false, false));
        self::assertStringNotContainsString('gzip_static', $this->render(true, false));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_compression_off_never_emits_it(): void
    {
        $_ENV['EDGE_COMPRESSION'] = $_SERVER['EDGE_COMPRESSION'] = 'off';
        $out = $this->render(true, true);

        self::assertStringNotContainsString('gzip_static', $out);
        self::assertStringNotContainsString('gzip on;', $out);
    }
}
