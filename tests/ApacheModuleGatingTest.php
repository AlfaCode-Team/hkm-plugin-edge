<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Edge;

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
 * Apache treats an unknown directive as a HARD configtest failure, so anything
 * optional has to be gated on the module that provides it. Two were not —
 * `SetEnv` (mod_env) and `RewriteEngine` (mod_rewrite, which Debian/Ubuntu do
 * NOT enable by default) — and `+TLSv1.3` was named on builds whose TLS library
 * has never heard of it (macOS/LibreSSL, RHEL 7, Ubuntu 18.04).
 *
 * Each case below took the whole vhost down, not just the feature.
 */
final class ApacheModuleGatingTest extends TestCase
{
    /** @param list<string> $modules */
    private function render(array $modules, TlsMode $mode = TlsMode::Ssl, bool $tls13 = true): string
    {
        $site = new Site(
            name: 'ap',
            docroot: '/srv/ap/app/public',
            publicDomains: ['ap.example', 'www.ap.example'],
            localDomains: [],
            model: ServeModel::Fpm,
            upstream: 'unix:/run/php/php8.4-fpm.sock',
            env: ['APP_ENV' => 'production', 'HKM_KERNEL_HOME' => '/opt/hkm'],
            root: '/srv/ap',
        );

        [, $body] = (new ConfigRenderer(new HostPaths(apacheTls13: $tls13)))->render(
            Strategy::ApacheOnly,
            [$site],
            new TlsConfig($mode, '/tmp/c.pem', '/tmp/k.pem'),
            new ServerStack(false, false, false, true, true, false, $modules, false),
            CacheProfile::Production,
        );

        return $body;
    }

    // ── mod_env ───────────────────────────────────────────────────────────────

    public function test_setenv_is_emitted_when_mod_env_is_loaded(): void
    {
        $out = $this->render(['env', 'ssl', 'headers']);

        self::assertStringContainsString('SetEnv APP_ENV "production"', $out);
        self::assertStringContainsString('SetEnv HKM_KERNEL_HOME "/opt/hkm"', $out);
    }

    public function test_setenv_is_withheld_when_mod_env_is_absent(): void
    {
        // Without the module the directive is "Invalid command 'SetEnv'" and the
        // whole config is refused — so the run-env is reported, not emitted.
        $out = $this->render(['ssl', 'headers']);

        self::assertStringNotContainsString('SetEnv', $out);
        self::assertStringContainsString('mod_env is not loaded', $out);
        self::assertStringContainsString('APP_ENV', $out);   // still says WHAT is missing
    }

    // ── mod_rewrite ───────────────────────────────────────────────────────────

    public function test_the_https_redirect_uses_mod_rewrite_when_available(): void
    {
        $out = $this->render(['env', 'ssl', 'headers', 'rewrite'], TlsMode::Both);

        self::assertStringContainsString('RewriteEngine On', $out);
        self::assertStringContainsString('RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]', $out);
    }

    public function test_the_redirect_falls_back_to_mod_alias(): void
    {
        // Debian/Ubuntu ship with mod_rewrite DISABLED (a2enmod rewrite), so this
        // is the stock-Apache path, not an exotic one.
        $out = $this->render(['env', 'ssl', 'headers', 'alias'], TlsMode::Both);

        self::assertStringNotContainsString('RewriteEngine', $out);
        self::assertStringContainsString('Redirect permanent / https://ap.example/', $out);
    }

    public function test_the_redirect_degrades_to_a_note_when_neither_module_exists(): void
    {
        $out = $this->render(['env', 'ssl', 'headers'], TlsMode::Both);

        self::assertStringNotContainsString('RewriteEngine', $out);
        self::assertStringNotContainsString('Redirect permanent', $out);
        self::assertStringContainsString('No HTTPS redirect', $out);
    }

    // ── TLS 1.3 ───────────────────────────────────────────────────────────────

    public function test_tls13_is_named_when_the_build_supports_it(): void
    {
        $out = $this->render(['env', 'ssl', 'headers'], tls13: true);

        self::assertStringContainsString('SSLProtocol -all +TLSv1.2 +TLSv1.3', $out);
    }

    public function test_tls13_is_dropped_when_the_build_refuses_it(): void
    {
        // `SSLProtocol: Illegal protocol 'TLSv1.3'` is fatal: naming it cost the
        // entire vhost, not just TLS 1.3.
        $out = $this->render(['env', 'ssl', 'headers'], tls13: false);

        self::assertStringContainsString('SSLProtocol -all +TLSv1.2', $out);
        self::assertStringNotContainsString('+TLSv1.3', $out);
        self::assertStringContainsString('TLSv1.3 omitted', $out);   // never a silent downgrade
    }

    public function test_the_nginx_side_is_unaffected_by_the_apache_verdict(): void
    {
        // nginx takes its protocol list straight from config; the Apache build's
        // limitation must not leak across.
        $site = new Site('n', '/srv/n/app/public', ['n.example'], [], ServeModel::Fpm,
            '127.0.0.1:9000', [], null, '/srv/n');
        [, $body] = (new ConfigRenderer(new HostPaths(apacheTls13: false)))->render(
            Strategy::NginxOnly, [$site],
            new TlsConfig(TlsMode::Ssl, '/tmp/c.pem', '/tmp/k.pem'),
            new ServerStack(true, true, false, false, false, false, [], false),
            CacheProfile::Production,
        );

        self::assertStringContainsString('ssl_protocols TLSv1.2 TLSv1.3;', $body);
    }
}
