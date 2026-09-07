<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Edge;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plugins\Edge\Infrastructure\HostPaths;

/**
 * Edge writes a config that ANOTHER program has to load, so every assumption it
 * makes about the host is load-bearing: nginx refuses to start when an
 * `access_log` directory is missing, and `http2 on;` is a hard configtest
 * failure on every nginx older than 1.25.1.
 *
 * These tests run against RECORDED `-V` banners rather than the machine the
 * suite happens to be on — that is the only way a Linux CI box can prove the
 * macOS and RHEL layouts resolve correctly.
 */
final class HostPathsTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/edge-paths-' . bin2hex(random_bytes(4));
        @mkdir($this->dir . '/logs', 0755, true);
    }

    protected function tearDown(): void
    {
        @rmdir($this->dir . '/logs');
        @rmdir($this->dir);
    }

    // ── the HTTP/2 spelling ───────────────────────────────────────────────────

    /** @return array<string, array{?string, bool}> */
    public static function versions(): array
    {
        return [
            'Ubuntu 22.04 (1.18)'  => ['1.18.0', false],
            'RHEL 9 (1.20)'        => ['1.20.1', false],
            'Debian 12 (1.22)'     => ['1.22.1', false],
            'just before (1.25.0)' => ['1.25.0', false],
            'the release (1.25.1)' => ['1.25.1', true],
            'current (1.27.4)'     => ['1.27.4', true],
            'unknown'              => [null,     true],
        ];
    }

    #[DataProvider('versions')]
    public function test_http2_directive_is_gated_on_the_installed_version(?string $version, bool $supported): void
    {
        // `http2 on;` arrived in 1.25.1. Older builds treat it as an unknown
        // directive and REFUSE to load — so this is the difference between a
        // config that works and one that takes the server down on reload.
        self::assertSame($supported, (new HostPaths(nginxVersion: $version))->supportsHttp2Directive());
    }

    // ── log directories ───────────────────────────────────────────────────────

    public function test_nginx_log_dir_comes_from_the_compiled_in_path(): void
    {
        $paths = HostPaths::fromBanners(
            "nginx version: nginx/1.27.4\nconfigure arguments: --error-log-path={$this->dir}/logs/error.log",
            '',
        );

        self::assertSame($this->dir . '/logs', $paths->nginxLogDir);
        self::assertSame('1.27.4', $paths->nginxVersion);
        self::assertTrue($paths->hasNginxLogs());
    }

    public function test_a_relative_compiled_path_is_resolved_against_the_prefix(): void
    {
        // The vanilla source default is the RELATIVE `logs/error.log`; on its own
        // it names nothing, so it only means something joined to --prefix.
        $paths = HostPaths::fromBanners(
            "nginx version: nginx/1.27.4\nconfigure arguments: --prefix={$this->dir} --error-log-path=logs/error.log",
            '',
        );

        self::assertSame($this->dir . '/logs', $paths->nginxLogDir);
    }

    public function test_a_non_path_error_log_is_ignored(): void
    {
        // Container images compile with `--error-log-path=/dev/stderr`; dirname()
        // of that is `/dev`, which must never become the access-log directory.
        $paths = HostPaths::fromBanners(
            "nginx version: nginx/1.27.4\nconfigure arguments: --error-log-path=/dev/stderr",
            '',
        );

        self::assertNotSame('/dev', $paths->nginxLogDir);
        self::assertTrue($paths->nginxLogDir === '' || is_dir($paths->nginxLogDir));
    }

    public function test_an_unresolvable_log_dir_is_empty_rather_than_guessed(): void
    {
        // '' is the instruction to OMIT the directives. Naming a directory that
        // is not there would produce a config nginx cannot load at all.
        $paths = HostPaths::fromBanners('nginx version: nginx/1.27.4', '');

        self::assertTrue($paths->nginxLogDir === '' || is_dir($paths->nginxLogDir));
        self::assertSame($paths->nginxLogDir !== '', $paths->hasNginxLogs());
    }

    public function test_apache_log_dir_joins_a_relative_default_to_the_server_root(): void
    {
        // macOS reports HTTPD_ROOT="/usr" + DEFAULT_ERRORLOG="logs/error_log";
        // Debian reports an absolute path. Both have to work.
        $paths = HostPaths::fromBanners('', "Server version: Apache/2.4.62\n"
            . " -D HTTPD_ROOT=\"{$this->dir}\"\n -D DEFAULT_ERRORLOG=\"logs/error_log\"\n");

        self::assertSame($this->dir . '/logs', $paths->apacheLogDir);
    }

    public function test_apache_log_dir_takes_an_absolute_default_as_is(): void
    {
        $paths = HostPaths::fromBanners('', " -D HTTPD_ROOT=\"/etc/httpd\"\n"
            . " -D DEFAULT_ERRORLOG=\"{$this->dir}/logs/error_log\"\n");

        self::assertSame($this->dir . '/logs', $paths->apacheLogDir);
    }

    // ── platform defaults ─────────────────────────────────────────────────────

    /**
     * The pair shares a directory EXCEPT where the Linux split layout is real.
     *
     * The blanket "always share" form of this test passed on macOS and failed on
     * Linux CI, because it contradicted what sslBase() deliberately does: where
     * /etc/ssl/private exists it is the mode-0700 directory that makes filing the
     * key apart from the certificate safe, and keeping the split is correct.
     *
     * The bug it was written for is the OTHER case — macOS has /etc/ssl/certs but
     * no private/, so resolving each half independently filed the pair in two
     * places and half the layout pointed at a directory that does not exist.
     *
     * It branches on the same condition the code does because sslBase() probes
     * the real filesystem; there is no seam to inject a layout through.
     */
    public function test_the_certificate_and_its_key_share_a_directory_unless_the_split_layout_exists(): void
    {
        $cert = HostPaths::defaultSslCert();
        $key  = HostPaths::defaultSslKey();

        if (is_dir('/etc/ssl/private')) {
            self::assertSame('/etc/ssl/certs', dirname($cert));
            self::assertSame('/etc/ssl/private', dirname($key));

            return;
        }

        self::assertSame(dirname($cert), dirname($key));
    }

    public function test_the_hosts_file_default_matches_the_platform(): void
    {
        $expected = PHP_OS_FAMILY === 'Windows' ? 'etc\\hosts' : '/etc/hosts';
        self::assertStringEndsWith($expected, HostPaths::defaultHostsFile());
    }

    public function test_service_directories_are_empty_when_the_manager_is_absent(): void
    {
        // '' is what makes `edge:service --write` ask for a target instead of
        // dropping a systemd unit on a host with no systemd.
        foreach ([HostPaths::defaultSystemdDir(), HostPaths::defaultSupervisorDir()] as $dir) {
            self::assertTrue($dir === '' || is_dir($dir));
        }
    }
}
