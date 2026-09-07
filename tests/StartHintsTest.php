<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Edge;

use PHPUnit\Framework\TestCase;
use Plugins\Edge\Infrastructure\SystemProbe;

/**
 * `edge:apply` answers `strategy: none` when no web server is RUNNING, and then
 * has nothing to write. On a systemd box that is rare — the package manager
 * starts and enables the service. Under Homebrew it is the DEFAULT, because
 * `brew install nginx` installs it stopped, so a Mac user meets "no active web
 * server" on a machine where nginx is plainly installed and reads it as "this
 * tool does not work here".
 *
 * The gap between those two readings is one command. These tests pin the wording
 * per platform, and run against INJECTED facts rather than the machine the suite
 * is on — the same reason HostPathsTest works from recorded banners, and the only
 * way a Linux CI box can prove the Homebrew branch.
 */
final class StartHintsTest extends TestCase
{
    public function test_nothing_stopped_says_nothing(): void
    {
        self::assertSame([], SystemProbe::hintsFor([], osFamily: 'Darwin', hasBrew: true, hasSystemd: false));
    }

    public function test_homebrew_mac_names_brew_services_for_each_stopped_server(): void
    {
        $hints = SystemProbe::hintsFor(['nginx', 'httpd'], osFamily: 'Darwin', hasBrew: true, hasSystemd: false);

        self::assertContains('  sudo brew services start nginx', $hints);
        self::assertContains('  sudo brew services start httpd', $hints);
        self::assertStringContainsString('Homebrew', $hints[0]);
    }

    /**
     * Not decoration: without sudo, `brew services start` registers a per-user
     * LaunchAgent that cannot bind :80/:443, and the failure surfaces later at
     * startup where nothing connects it back to this advice.
     */
    public function test_the_homebrew_hint_explains_why_sudo_is_required(): void
    {
        $hints = SystemProbe::hintsFor(['nginx'], osFamily: 'Darwin', hasBrew: true, hasSystemd: false);

        self::assertStringContainsString(':80/:443', implode("\n", $hints));
    }

    public function test_systemd_host_names_systemctl(): void
    {
        $hints = SystemProbe::hintsFor(['nginx', 'apache2'], osFamily: 'Linux', hasBrew: false, hasSystemd: true);

        self::assertContains('  sudo systemctl start nginx', $hints);
        self::assertContains('  sudo systemctl start apache2', $hints);
        self::assertStringNotContainsString('brew', implode("\n", $hints));
    }

    /** A Mac WITHOUT Homebrew must not be told to run a command it does not have. */
    public function test_mac_without_brew_does_not_suggest_brew(): void
    {
        $hints = SystemProbe::hintsFor(['nginx'], osFamily: 'Darwin', hasBrew: false, hasSystemd: false);

        self::assertStringNotContainsString('brew', implode("\n", $hints));
        self::assertStringContainsString('nginx', implode("\n", $hints));
    }

    /** Homebrew wins over systemd on a Mac — `brew services` is what reboots restore. */
    public function test_homebrew_wins_over_systemd_on_a_mac(): void
    {
        $hints = SystemProbe::hintsFor(['nginx'], osFamily: 'Darwin', hasBrew: true, hasSystemd: true);

        self::assertStringContainsString('brew services', implode("\n", $hints));
    }

    /** Linux with brew installed (linuxbrew) still gets systemctl — it is the real manager there. */
    public function test_linuxbrew_still_gets_systemctl(): void
    {
        $hints = SystemProbe::hintsFor(['nginx'], osFamily: 'Linux', hasBrew: true, hasSystemd: true);

        self::assertContains('  sudo systemctl start nginx', $hints);
    }

    /** Neither manager: still name what is stopped rather than going silent. */
    public function test_container_without_a_service_manager_still_names_the_servers(): void
    {
        $hints = SystemProbe::hintsFor(['nginx', 'httpd'], osFamily: 'Linux', hasBrew: false, hasSystemd: false);

        self::assertStringContainsString('nginx, httpd', implode("\n", $hints));
    }

    // ── Windows ───────────────────────────────────────────────────────────────

    public function test_windows_names_net_start(): void
    {
        $hints = SystemProbe::hintsFor(['nginx'], osFamily: 'Windows', hasBrew: false, hasSystemd: false);

        self::assertContains('  net start nginx', $hints);
        self::assertStringNotContainsString('brew', implode("\n", $hints));
        self::assertStringNotContainsString('systemctl', implode("\n", $hints));
    }

    /**
     * Neither server is a Windows service unless it was installed as one, and
     * `net start` on a bare nginx.exe fails with "service name is invalid" —
     * which reads as a broken hint rather than the wrong start method.
     */
    public function test_windows_says_net_start_only_applies_to_a_real_service(): void
    {
        $hints = implode("\n", SystemProbe::hintsFor(['nginx'], osFamily: 'Windows', hasBrew: false, hasSystemd: false));

        self::assertStringContainsString('installed AS a service', $hints);
        self::assertStringContainsString('nginx.exe', $hints);
    }

    /** Windows wins over every other branch, even if the probes somehow answered true. */
    public function test_windows_is_not_overridden_by_brew_or_systemd(): void
    {
        $hints = implode("\n", SystemProbe::hintsFor(['nginx'], osFamily: 'Windows', hasBrew: true, hasSystemd: true));

        self::assertStringContainsString('net start', $hints);
        self::assertStringNotContainsString('brew services', $hints);
    }

    // ── the binary-lookup command ─────────────────────────────────────────────

    public function test_which_uses_where_on_windows_and_command_v_elsewhere(): void
    {
        self::assertStringStartsWith('where ',     SystemProbe::whichCommand('nginx', 'Windows'));
        self::assertStringStartsWith('command -v ', SystemProbe::whichCommand('nginx', 'Linux'));
        self::assertStringStartsWith('command -v ', SystemProbe::whichCommand('nginx', 'Darwin'));
    }

    /**
     * The binary reaches a shell on both platforms, so it is escaped on both.
     *
     * Asserted as "equals escapeshellarg()" rather than "does not contain ; rm":
     * the escaped form still CONTAINS those characters, safely quoted, so the
     * substring check passed on Windows and failed on POSIX while proving
     * nothing either way. What matters is that the value is quoted at all —
     * run() separately refuses any command carrying shell metacharacters.
     */
    public function test_which_quotes_the_binary_name(): void
    {
        $hostile = 'a; rm -rf /';

        self::assertSame('where ' . escapeshellarg($hostile), SystemProbe::whichCommand($hostile, 'Windows'));
        self::assertSame('command -v ' . escapeshellarg($hostile), SystemProbe::whichCommand($hostile, 'Linux'));
    }
}
