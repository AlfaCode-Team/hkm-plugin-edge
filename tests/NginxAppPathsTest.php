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
 * `EDGE_APP_PATHS` — the prefixes the application serves, not the disk.
 *
 * THE BUG THIS EXISTS FOR. The static-asset location matches on EXTENSION
 * alone and resolves the file under the public root, answering 404 on a miss
 * without ever reaching the application. A controller that streams a stored
 * document at `/attachments/…/scan.png` is therefore unreachable: every one of
 * its URLs ends in an asset extension, so nginx answers for it. The symptom is
 * a broken image beside a record whose file is on disk and whose route works —
 * and nothing in the application's own logs, because no request arrived.
 *
 * The rule is asserted by RUNNING the generated regex rather than by matching
 * its text: what matters is which URLs it claims, and a test that compared
 * strings would pass on a pattern nginx reads differently.
 *
 * These set non-default config, so they run in a SEPARATE PROCESS —
 * edge_config_fallback() caches the parsed config in a static.
 */
final class NginxAppPathsTest extends TestCase
{
    private function site(ServeModel $model = ServeModel::Fpm): Site
    {
        return new Site(
            name: 'apppaths',
            docroot: '/srv/apppaths/app/public',
            publicDomains: ['apppaths.example'],
            localDomains: [],
            model: $model,
            upstream: $model === ServeModel::Swoole ? '127.0.0.1:9501' : 'unix:/run/php/php8.4-fpm.sock',
            env: ['APP_ENV' => 'production'],
            swoole: $model === ServeModel::Swoole
                ? new \Plugins\Edge\Domain\SwooleOptions(host: '127.0.0.1', port: 9501)
                : null,
            root: '/srv/apppaths',
        );
    }

    private function render(ServeModel $model = ServeModel::Fpm): string
    {
        [, $body] = (new ConfigRenderer())->render(
            Strategy::NginxOnly,
            [$this->site($model)],
            new TlsConfig(TlsMode::Ssl, '/etc/ssl/certs/x.pem', '/etc/ssl/private/x.key'),
            new ServerStack(true, true, false, false, false, false, [], false),
            CacheProfile::Production,
        );

        return $body;
    }

    /**
     * Pull the static-asset location's regex out of the rendered config and
     * answer whether it claims a given URL — which is the only question the
     * bug was ever about.
     */
    /** The asset rule's regex, exactly as nginx will read it. */
    private function staticRulePattern(string $config): string
    {
        // The config holds SEVERAL `location ~*` rules (deny-by-extension,
        // deny-by-filename, the three upload guards). The asset rule is the one
        // carrying the served extension list, and picking "the first one" is
        // how this helper first tested the deny rule by mistake.
        self::assertSame(
            1,
            preg_match('/^    location ~\* (\S*\\\\\.\(css\|js\|.+)\s\{$/m', $config, $m),
            'expected exactly one static-asset location in the rendered config',
        );

        return $m[1];
    }

    private function staticRuleClaims(string $config, string $uri): bool
    {
        return preg_match('#' . $this->staticRulePattern($config) . '#i', $uri) === 1;
    }

    public function test_by_default_the_static_rule_claims_every_asset_url(): void
    {
        $config = $this->render();

        self::assertTrue($this->staticRuleClaims($config, '/build/app.a1b2c3.js'));
        // NOTHING IS EXEMPT WITHOUT AN OPT-IN. A project that never sets the
        // variable must get byte-for-byte the config it got before it existed.
        self::assertTrue($this->staticRuleClaims($config, '/attachments/x/scan.png'));
        // Byte-identical to the rule as it stood before the option existed.
        self::assertStringStartsWith('\\.(css|js|', $this->staticRulePattern($config));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_a_declared_prefix_is_left_to_the_application(): void
    {
        $_ENV['EDGE_APP_PATHS'] = '/attachments/';
        $config = $this->render();

        self::assertFalse($this->staticRuleClaims($config, '/attachments/b/1/customers/9/id.png'));
        // AND NOTHING ELSE MOVES. The exemption is the prefix, not the extension.
        self::assertTrue($this->staticRuleClaims($config, '/build/app.a1b2c3.js'));
        self::assertTrue($this->staticRuleClaims($config, '/img/logo.png'));
        // A NEIGHBOUR THAT MERELY STARTS THE SAME WAY IS NOT THE SAME PREFIX.
        self::assertTrue($this->staticRuleClaims($config, '/attachments-public/x.png'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_a_prefix_written_without_its_slash_means_the_same_thing(): void
    {
        $_ENV['EDGE_APP_PATHS'] = '/attachments';
        $config = $this->render();

        self::assertFalse($this->staticRuleClaims($config, '/attachments/x/scan.png'));
        self::assertTrue($this->staticRuleClaims($config, '/attachments-public/x.png'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_several_prefixes_are_all_exempt(): void
    {
        $_ENV['EDGE_APP_PATHS'] = '/attachments/, /invoices/';
        $config = $this->render();

        self::assertFalse($this->staticRuleClaims($config, '/attachments/x.png'));
        self::assertFalse($this->staticRuleClaims($config, '/invoices/2026/03.pdf'));
        self::assertTrue($this->staticRuleClaims($config, '/build/app.js'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_the_prefix_reaches_the_swoole_config_too(): void
    {
        // Two templates substitute the same static block. A fix applied to one
        // of them is a fix half the deployments do not get.
        $_ENV['EDGE_APP_PATHS'] = '/attachments/';
        $config = $this->render(ServeModel::Swoole);

        self::assertFalse($this->staticRuleClaims($config, '/attachments/x.png'));
        self::assertTrue($this->staticRuleClaims($config, '/build/app.js'));
    }

    /**
     * THE VALUE IS INTERPOLATED INTO A REGEX, so a junk entry is not a wrong
     * path — it is a different rule. Each of these is dropped rather than
     * emitted, and dropping them leaves the default rule exactly as it was.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_unusable_prefixes_are_dropped_not_emitted(): void
    {
        $_ENV['EDGE_APP_PATHS'] = implode(',', [
            '/../etc/',          // traversal
            'attachments/',      // no leading slash
            '/',                 // every URL — would silently disable the rule
            '/a(b|c)/',          // regex metacharacters
            '/we bster/',        // space
        ]);
        $config = $this->render();

        // Every entry dropped, so the rule is the untouched default.
        self::assertStringStartsWith('\\.(css|js|', $this->staticRulePattern($config));
        self::assertTrue($this->staticRuleClaims($config, '/build/app.js'));
        self::assertTrue($this->staticRuleClaims($config, '/etc/x.png'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_a_dot_in_a_prefix_is_a_dot_and_not_any_character(): void
    {
        $_ENV['EDGE_APP_PATHS'] = '/v1.0/';
        $config = $this->render();

        self::assertFalse($this->staticRuleClaims($config, '/v1.0/x.png'));
        // Unescaped, `.` would match the `X` here and exempt a path nobody named.
        self::assertTrue($this->staticRuleClaims($config, '/v1X0/x.png'));
    }
}
