<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Edge;

use PHPUnit\Framework\Attributes\DataProvider;
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
 * Feeds every generated DEVELOPMENT/PRODUCTION × TLS-mode vhost to a real
 * `nginx -t`. Skips cleanly when nginx is not installed so the suite still runs
 * everywhere, but proves the output is syntactically valid where it can.
 *
 * The config is rendered against the REAL host (its log directory, its nginx
 * version) and validated unmodified. It used to be rewritten first — the log
 * path replaced and `http2 on;` stripped — which meant the two directives most
 * likely to be wrong for this machine were the two this test could never catch.
 */
final class NginxSyntaxTest extends TestCase
{
    private ?string $dir = null;

    protected function setUp(): void
    {
        if ($this->nginxBinary() === null) {
            self::markTestSkipped('nginx not installed — skipping syntax validation.');
        }
        $this->dir = sys_get_temp_dir() . '/edge-nginx-' . bin2hex(random_bytes(4));
        @mkdir($this->dir . '/logs', 0755, true);
        // Self-signed cert so ssl_certificate directives resolve.
        $cert = $this->dir . '/cert.pem';
        $key  = $this->dir . '/key.pem';
        exec(sprintf(
            'openssl req -x509 -newkey rsa:2048 -keyout %s -out %s -days 1 -nodes -subj /CN=test 2>/dev/null',
            escapeshellarg($key),
            escapeshellarg($cert),
        ));
        file_put_contents($this->dir . '/fastcgi_params', "fastcgi_param QUERY_STRING \$query_string;\n");
    }

    protected function tearDown(): void
    {
        if ($this->dir !== null && is_dir($this->dir)) {
            exec('rm -rf ' . escapeshellarg($this->dir));
        }
    }

    /** @return array<string, array{CacheProfile, TlsMode}> */
    public static function matrix(): array
    {
        return NginxConfigRendererTest::matrix();
    }

    #[DataProvider('matrix')]
    public function test_generated_vhost_passes_nginx_t(CacheProfile $profile, TlsMode $mode): void
    {
        $site = new Site(
            name: 'hkmstd',
            docroot: '/srv/hkmstd/app/public',
            publicDomains: ['hkmstd.com', 'www.hkmstd.com'],
            localDomains: [],
            model: ServeModel::Fpm,
            upstream: 'unix:/run/php/php8.4-fpm.sock',
            env: ['APP_ENV' => 'production'],
            root: '/srv/hkmstd',
        );
        // Brotli off → gzip-only output, portable to nginx builds without ngx_brotli.
        $stack = new ServerStack(true, true, false, false, false, false, [], false);
        $tls   = new TlsConfig($mode, $this->dir . '/cert.pem', $this->dir . '/key.pem');

        // Render against THIS host: the sandbox log directory and the version of
        // the very nginx that is about to validate the output. Nothing about the
        // logs or the HTTP/2 spelling is rewritten afterwards — rewriting them is
        // how the portability bug in both used to pass this test unnoticed.
        $paths = new HostPaths(
            nginxLogDir:  $this->dir . '/logs',
            nginxVersion: $this->nginxVersion(),
        );

        [, $body] = (new ConfigRenderer($paths))->render(Strategy::NginxOnly, [$site], $tls, $stack, $profile);

        // fastcgi_params lives at the server's own prefix, which the sandbox
        // config does not share.
        $body = str_replace('include fastcgi_params;', 'include ' . $this->dir . '/fastcgi_params;', $body);
        // Rewrite privileged ports to high ports — some nginx builds open the
        // listen sockets during `-t`, and CI runs non-root (can't bind <1024). The
        // real 80/443/444 ports are asserted by the behavioral tests.
        $body = (string) preg_replace('/\blisten (\[::\]:)?443\b/', 'listen ${1}8443', $body);
        $body = (string) preg_replace('/\blisten (\[::\]:)?444\b/', 'listen ${1}8444', $body);
        $body = (string) preg_replace('/\blisten (\[::\]:)?80\b/', 'listen ${1}8080', $body);
        // pid + error_log are supplied via -g (universally supported) rather than
        // written into the config body or the newer -e flag (older nginx rejects
        // -e), and kept OUT of the body so there is no duplicate directive.
        $conf = $this->dir . '/site.conf';
        file_put_contents($conf, sprintf(
            "events {}\nhttp {\n"
            . "  access_log %1\$s/logs/access.log;\n  client_body_temp_path %1\$s/logs/body;\n"
            . "  proxy_temp_path %1\$s/logs/proxy;\n  fastcgi_temp_path %1\$s/logs/fcgi;\n"
            . "  limit_req_zone \$binary_remote_addr zone=general:10m rate=10r/s;\n"
            . "  limit_conn_zone \$binary_remote_addr zone=perip:10m;\n%2\$s\n}\n",
            $this->dir,
            $body,
        ));

        exec(sprintf(
            '%s -t -c %s -p %s -g %s 2>&1',
            $this->nginxBinary(),
            escapeshellarg($conf),
            escapeshellarg($this->dir),
            escapeshellarg("pid {$this->dir}/logs/nginx.pid; error_log {$this->dir}/logs/main.log;"),
        ), $out, $code);
        $output = implode("\n", $out);

        // "syntax is ok" is the validity signal. Exit code is NOT asserted: a
        // locked-down runner can still emit a non-fatal alert (e.g. the compiled
        // default error-log path) that flips the code without the generated config
        // being wrong. A genuine config error would omit "syntax is ok".
        self::assertStringContainsString('syntax is ok', $output, $output);
        self::assertStringNotContainsString('[emerg]', $output, $output);
    }

    /** The version of the nginx that will validate the output, or null. */
    private function nginxVersion(): ?string
    {
        $banner = (string) shell_exec($this->nginxBinary() . ' -v 2>&1');

        return preg_match('#nginx/(\d+\.\d+\.\d+)#', $banner, $m) === 1 ? $m[1] : null;
    }

    private function nginxBinary(): ?string
    {
        $path = trim((string) shell_exec('command -v nginx 2>/dev/null'));

        return $path !== '' ? $path : null;
    }
}
