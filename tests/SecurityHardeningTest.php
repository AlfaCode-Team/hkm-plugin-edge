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
 * The security posture of the generated config. Each item here corresponds to a
 * concrete finding — a header that leaks, a directive that never ran, a bypass —
 * so a regression is a security regression, not a formatting change.
 *
 * Tests that need non-default configuration run in a SEPARATE PROCESS:
 * edge_config_fallback() caches the parsed config in a static, so the env has to
 * be set before the process makes its first edge_config() call.
 */
final class SecurityHardeningTest extends TestCase
{
    private function site(ServeModel $model = ServeModel::Fpm): Site
    {
        return new Site(
            name: 'sec',
            docroot: '/srv/sec/app/public',
            publicDomains: ['sec.example'],
            localDomains: [],
            model: $model,
            upstream: $model === ServeModel::Swoole ? '127.0.0.1:9501' : 'unix:/run/php/php8.4-fpm.sock',
            env: ['APP_ENV' => 'production'],
            swoole: $model === ServeModel::Swoole
                ? new \Plugins\Edge\Domain\SwooleOptions(host: '127.0.0.1', port: 9501)
                : null,
            root: '/srv/sec',
        );
    }

    /** @param list<string> $apacheModules */
    private function render(
        Strategy $strategy = Strategy::NginxOnly,
        TlsMode $mode = TlsMode::Ssl,
        ServeModel $model = ServeModel::Fpm,
        ?ServerStack $stack = null,
        array $apacheModules = ['headers', 'deflate', 'filter', 'ssl', 'reqtimeout'],
    ): string {
        $stack ??= $strategy === Strategy::ApacheOnly
            ? new ServerStack(false, false, false, true, true, false, $apacheModules, false)
            : new ServerStack(true, true, false, false, false, false, [], false);

        [, $body] = (new ConfigRenderer())->render(
            $strategy,
            [$this->site($model)],
            new TlsConfig($mode, '/etc/ssl/certs/x.pem', '/etc/ssl/private/x.key'),
            $stack,
            CacheProfile::Production,
        );

        return $body;
    }

    // ── httpoxy (CVE-2016-5385) ───────────────────────────────────────────────

    public function test_fastcgi_blanks_http_proxy(): void
    {
        // A `Proxy:` request header becomes HTTP_PROXY in the CGI environment and
        // redirects the app's own outbound calls. The distro fastcgi_params does
        // NOT clear it, so the vhost must.
        self::assertStringContainsString('fastcgi_param HTTP_PROXY "";', $this->render());
    }

    public function test_apache_drops_the_proxy_request_header(): void
    {
        self::assertStringContainsString('RequestHeader unset Proxy early', $this->render(Strategy::ApacheOnly));
    }

    // ── Throttling returns 429, not 503 ───────────────────────────────────────

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_rate_limit_answers_429_not_the_error_page(): void
    {
        // 503 is nginx's default AND is mapped to /50x.html by this vhost, so a
        // throttled visitor would see the server-error page and the event would
        // look like an outage in the logs.
        $_ENV['EDGE_HTTP_PRELUDE'] = $_SERVER['EDGE_HTTP_PRELUDE'] = '1';
        $config = $this->render();

        self::assertStringContainsString('limit_req_status 429;', $config);
        self::assertStringContainsString('limit_conn_status 429;', $config);
        self::assertStringContainsString('error_page 500 502 503 504', $config); // still mapped, now unreachable by throttling
    }

    // ── Version fingerprinting ────────────────────────────────────────────────

    public function test_upstream_identifying_headers_are_stripped(): void
    {
        self::assertStringContainsString('fastcgi_hide_header X-Powered-By;', $this->render());
        self::assertStringContainsString('proxy_hide_header X-Powered-By;', $this->render(model: ServeModel::Swoole));
        self::assertStringContainsString('Header always unset X-Powered-By', $this->render(Strategy::ApacheOnly));
    }

    // ── Isolation headers ─────────────────────────────────────────────────────

    public function test_isolation_headers_are_emitted_on_both_fronts(): void
    {
        foreach ([$this->render(), $this->render(Strategy::ApacheOnly)] as $config) {
            self::assertStringContainsString('Permissions-Policy', $config);
            self::assertStringContainsString('Cross-Origin-Opener-Policy', $config);
            self::assertStringContainsString('Cross-Origin-Resource-Policy', $config);
        }
    }

    public function test_csp_is_absent_until_configured(): void
    {
        // An unconfigured CSP must not be invented — a wrong policy breaks the app.
        self::assertStringNotContainsString('add_header Content-Security-Policy "default', $this->render());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_csp_report_only_uses_the_report_only_header(): void
    {
        $_ENV['EDGE_CSP'] = $_SERVER['EDGE_CSP'] = "default-src 'self'";
        $_ENV['EDGE_CSP_REPORT_ONLY'] = $_SERVER['EDGE_CSP_REPORT_ONLY'] = '1';
        $config = $this->render();

        self::assertStringContainsString('Content-Security-Policy-Report-Only "default-src \'self\'"', $config);
        self::assertStringNotContainsString('add_header Content-Security-Policy "default-src', $config);
    }

    // ── Deny lists ────────────────────────────────────────────────────────────

    public function test_private_keys_and_archives_are_denied(): void
    {
        // `location /` ends in try_files $uri, which serves ANY existing file, so
        // the deny list is the only guard for a stray key or backup dump.
        $config = $this->render();
        foreach (['pem', 'key', 'crt', 'p12', 'zip', 'tar', 'sqlite3'] as $ext) {
            self::assertMatchesRegularExpression('/location ~\* \\\\\.\([^)]*\b' . $ext . '\b/', $config, "{$ext} must be denied");
        }
    }

    public function test_build_metadata_is_denied_by_name(): void
    {
        $config = $this->render();
        self::assertStringContainsString('composer\.lock', $config);
        self::assertStringContainsString('package\.json', $config);
        // …but a blanket .json deny would break Vite manifests and webmanifests.
        self::assertStringNotContainsString('location ~* \.(json)$', $config);
    }

    public function test_apache_denies_the_same_paths_as_nginx(): void
    {
        $config = $this->render(Strategy::ApacheOnly);
        self::assertStringContainsString('<DirectoryMatch "/vendor/">', $config);
        self::assertStringContainsString('<FilesMatch "^\.">', $config);   // /.env is a FILE, DirectoryMatch misses it
        self::assertStringContainsString('composer\.lock', $config);
    }

    // ── PHP execution surface ─────────────────────────────────────────────────

    public function test_apache_executes_only_the_front_controller(): void
    {
        // A blanket <FilesMatch "\.php$"> SetHandler runs ANY .php that reaches the
        // docroot. nginx has always been front-controller-only; Apache must match.
        $config = $this->render(Strategy::ApacheOnly);

        self::assertMatchesRegularExpression('/<FilesMatch "\\\\\.php\$">\s*\n\s*Require all denied/', $config);
        self::assertMatchesRegularExpression('/<Files "index\.php">\s*\n\s*Require all granted\s*\n\s*SetHandler/', $config);
    }

    public function test_apache_does_not_consult_htaccess(): void
    {
        self::assertStringContainsString('AllowOverride None', $this->render(Strategy::ApacheOnly));
        self::assertStringNotContainsString('AllowOverride All', $this->render(Strategy::ApacheOnly));
    }

    public function test_disabling_htaccess_keeps_front_controller_routing(): void
    {
        // The app's public/.htaccess used to supply the "not a real file → send it
        // to index.php" rewrite. AllowOverride None stops Apache reading it, so the
        // vhost MUST carry the equivalent or every URL except / returns 404.
        $config = $this->render(Strategy::ApacheOnly);

        self::assertStringContainsString('FallbackResource /index.php', $config);
        self::assertStringContainsString('DirectoryIndex index.php', $config);

        // …and it belongs inside <Directory>, where FallbackResource is legal.
        $dirStart = strpos($config, '<Directory ');
        $dirEnd   = strpos($config, '</Directory>');
        self::assertIsInt($dirStart);
        self::assertIsInt($dirEnd);
        self::assertStringContainsString('FallbackResource', substr($config, $dirStart, $dirEnd - $dirStart));
    }

    public function test_openswoole_apache_site_has_no_fallback_resource(): void
    {
        // ProxyPass / already owns the whole URL space there; a FallbackResource
        // would point at a docroot the app server never uses.
        $config = $this->render(Strategy::ApacheOnly, model: ServeModel::Swoole);

        self::assertStringContainsString('ProxyPass', $config);
        self::assertStringNotContainsString('FallbackResource', $config);
    }

    // ── Context-sensitive Apache directives ───────────────────────────────────

    public function test_apache_context_only_directives_sit_in_a_legal_context(): void
    {
        $config = $this->render(Strategy::ApacheOnly);

        // Both of these are hard `apachectl configtest` failures in the wrong
        // place: TraceEnable is server-config only, <LimitExcept> is directory only.
        $vhost = substr($config, strpos($config, '<VirtualHost') ?: 0);
        self::assertStringNotContainsString('TraceEnable', $vhost, 'TraceEnable is not allowed inside <VirtualHost>');
        self::assertStringContainsString("\nTraceEnable Off", $config, 'TraceEnable belongs at file (server) level');

        $dirStart = strpos($config, '<Directory ');
        $dirEnd   = strpos($config, '</Directory>');
        self::assertIsInt($dirStart);
        self::assertIsInt($dirEnd);
        $directory = substr($config, $dirStart, $dirEnd - $dirStart);
        self::assertStringContainsString('<LimitExcept', $directory, '<LimitExcept> is only legal inside <Directory>');
    }

    public function test_apache_module_gated_directives_are_skipped_when_absent(): void
    {
        // An unknown directive is a hard configtest failure, so anything needing a
        // module must vanish when that module is not loaded.
        $bare = $this->render(Strategy::ApacheOnly, apacheModules: ['mpm_prefork']);

        self::assertStringNotContainsString('RequestReadTimeout', $bare);
        self::assertStringNotContainsString('Header always set', $bare);
        self::assertStringNotContainsString('SSLProtocol', $bare);
    }

    // ── Slow-request guards ───────────────────────────────────────────────────

    public function test_slowloris_guards_are_present(): void
    {
        $config = $this->render();
        foreach (['client_header_timeout', 'client_body_timeout', 'send_timeout', 'reset_timedout_connection'] as $d) {
            self::assertStringContainsString($d, $config);
        }
        self::assertStringContainsString('RequestReadTimeout', $this->render(Strategy::ApacheOnly));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_max_body_is_configurable_and_converted_for_apache(): void
    {
        $_ENV['EDGE_MAX_BODY'] = $_SERVER['EDGE_MAX_BODY'] = '100m';

        self::assertStringContainsString('client_max_body_size 100m;', $this->render());
        self::assertStringContainsString('LimitRequestBody 104857600', $this->render(Strategy::ApacheOnly));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_a_bogus_max_body_falls_back_instead_of_injecting(): void
    {
        $_ENV['EDGE_MAX_BODY'] = $_SERVER['EDGE_MAX_BODY'] = "25m;\n    deny all";

        $config = $this->render();
        self::assertStringContainsString('client_max_body_size 25m;', $config);
        self::assertStringNotContainsString('deny all;
    client', $config);
    }

    // ── Upload hardening ──────────────────────────────────────────────────────

    public function test_uploaded_svg_and_html_cannot_render_inline(): void
    {
        $config = $this->render();

        self::assertStringContainsString('^/storage/.*\.(svg', $config);
        self::assertStringContainsString('add_header Content-Disposition "attachment" always;', $config);
        self::assertStringContainsString('sandbox; default-src \'none\'', $config);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_upload_guard_can_deny_outright_or_be_disabled(): void
    {
        $_ENV['EDGE_UPLOAD_MODE'] = $_SERVER['EDGE_UPLOAD_MODE'] = 'deny-risky';
        self::assertStringContainsString('^/storage/.*\.(svg', $this->render());
        self::assertStringNotContainsString('Content-Disposition', $this->render());
    }

    // ── PROXY protocol (client-IP recovery behind the SNI splitter) ───────────

    public function test_proxy_protocol_is_off_by_default(): void
    {
        // Enabling only one end breaks every request, so it must never appear
        // unless asked for.
        $streamStack = new ServerStack(true, true, true, true, true, false, [], false);
        $config = $this->render(Strategy::NginxStream, stack: $streamStack);

        self::assertStringNotContainsString('proxy_protocol', $config);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_proxy_protocol_is_emitted_at_both_ends_together(): void
    {
        $_ENV['EDGE_PROXY_PROTOCOL'] = $_SERVER['EDGE_PROXY_PROTOCOL'] = '1';
        $streamStack = new ServerStack(true, true, true, true, true, false, [], false);
        $config = $this->render(Strategy::NginxStream, stack: $streamStack);

        self::assertStringContainsString('proxy_protocol on;', $config);          // the stream router
        self::assertStringContainsString('ssl proxy_protocol;', $config);          // the backend listener
        self::assertStringContainsString('real_ip_header proxy_protocol;', $config);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_proxy_protocol_never_lands_on_a_directly_reachable_vhost(): void
    {
        // nginx-only with no router in front: a listener declaring proxy_protocol
        // would reject every ordinary client connection.
        $_ENV['EDGE_PROXY_PROTOCOL'] = $_SERVER['EDGE_PROXY_PROTOCOL'] = '1';

        self::assertStringNotContainsString('proxy_protocol', $this->render(Strategy::NginxOnly));
    }

    // ── Origin lockdown ───────────────────────────────────────────────────────

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_cloudflare_only_allows_the_edge_and_denies_the_rest(): void
    {
        $_ENV['EDGE_CLOUDFLARE_ONLY'] = $_SERVER['EDGE_CLOUDFLARE_ONLY'] = '1';
        $config = $this->render();

        self::assertStringContainsString('allow 173.245.48.0/20;', $config);
        self::assertStringContainsString('allow 127.0.0.1;', $config);
        self::assertMatchesRegularExpression('/allow [^\n]+;\n(\s*allow [^\n]+;\n)+\s*deny all;/', $config);
    }

    public function test_origin_lockdown_is_off_by_default(): void
    {
        self::assertStringNotContainsString('allow 173.245.48.0/20;', $this->render());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_origin_pull_mtls_is_emitted_when_a_ca_is_configured(): void
    {
        $_ENV['EDGE_ORIGIN_PULL_CA'] = $_SERVER['EDGE_ORIGIN_PULL_CA'] = '/etc/ssl/cf-origin-pull-ca.pem';
        $config = $this->render();

        self::assertStringContainsString('ssl_client_certificate /etc/ssl/cf-origin-pull-ca.pem;', $config);
        self::assertStringContainsString('ssl_verify_client on;', $config);
    }

    // ── OCSP stapling ─────────────────────────────────────────────────────────

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_stapling_ships_with_a_resolver_or_it_silently_does_nothing(): void
    {
        $_ENV['EDGE_SSL_STAPLING'] = $_SERVER['EDGE_SSL_STAPLING'] = '1';
        $config = $this->render();

        self::assertStringContainsString('ssl_stapling on;', $config);
        self::assertStringContainsString('resolver ', $config);
        self::assertStringContainsString('resolver_timeout ', $config);
    }

    // ── Injection resistance ──────────────────────────────────────────────────

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_hostile_config_values_cannot_inject_directives(): void
    {
        $_ENV['EDGE_DENY_EXT'] = $_SERVER['EDGE_DENY_EXT'] = 'env|bad) { root /etc; } location ~ (x';
        $_ENV['EDGE_DENY_FILES'] = $_SERVER['EDGE_DENY_FILES'] = '../../etc/passwd,ok.json';
        $_ENV['EDGE_HIDE_HEADERS'] = $_SERVER['EDGE_HIDE_HEADERS'] = "X-Ok,Bad\nadd_header X-Evil 1";
        $_ENV['EDGE_UPLOAD_PATHS'] = $_SERVER['EDGE_UPLOAD_PATHS'] = 'storage,../etc';

        $config = $this->render();

        self::assertStringNotContainsString('root /etc;', $config);
        self::assertStringNotContainsString('passwd', $config);
        self::assertStringNotContainsString('X-Evil', $config);
        self::assertStringNotContainsString('../etc', $config);
        // The safe entries alongside them still survive.
        self::assertStringContainsString('ok\.json', $config);
        self::assertStringContainsString('fastcgi_hide_header X-Ok;', $config);
    }
}
