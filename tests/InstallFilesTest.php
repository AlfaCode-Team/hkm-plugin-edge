<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Edge;

use PHPUnit\Framework\TestCase;
use Plugins\Edge\Application\EdgeService;
use Plugins\Edge\Domain\EdgePlan;
use Plugins\Edge\Domain\ServerStack;
use Plugins\Edge\Domain\Strategy;
use Plugins\Edge\Infrastructure\ConfigRenderer;
use Plugins\Edge\Infrastructure\HostsFileWriter;
use Plugins\Edge\Infrastructure\SiteCollector;
use Plugins\Edge\Infrastructure\SystemProbe;

/**
 * Installing a plan is the part that touches the live server.
 *
 * An SNI plan writes TWO files that are only correct together, so the write and
 * the rollback have to cover both: a plan that rolled back one half would leave
 * a splitter and a vhost set that disagree — which is worse than either the old
 * config or the new one.
 */
final class InstallFilesTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/edge-install-' . bin2hex(random_bytes(4));
        @mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    private function service(): EdgeService
    {
        $probe = new SystemProbe();

        return new EdgeService($probe, new SiteCollector($probe), new ConfigRenderer(), new HostsFileWriter());
    }

    /** @return array{ok: bool, backups: array<string, string>, steps: list<string>, message: string} */
    private function install(EdgePlan $plan): array
    {
        $m = new \ReflectionMethod(EdgeService::class, 'installFiles');

        return $m->invoke($this->service(), $plan);
    }

    /** @param array<string, string> $backups @return list<string> */
    private function restore(array $backups): array
    {
        $m = new \ReflectionMethod(EdgeService::class, 'restoreBackups');

        return $m->invoke($this->service(), $backups);
    }

    private function plan(?string $streamPath = null): EdgePlan
    {
        return new EdgePlan(
            new ServerStack(true, true, true, true, true),
            Strategy::NginxStream, [], [],
            $this->dir . '/vhosts.conf', "# vhosts v2\n",
            false,
            $streamPath,
            $streamPath === null ? null : "# splitter v2\n",
        );
    }

    public function test_both_halves_of_a_split_plan_are_written(): void
    {
        $result = $this->install($this->plan($this->dir . '/stream.conf'));

        self::assertTrue($result['ok']);
        self::assertSame("# vhosts v2\n", file_get_contents($this->dir . '/vhosts.conf'));
        self::assertSame("# splitter v2\n", file_get_contents($this->dir . '/stream.conf'));
        self::assertCount(2, $result['steps']);
    }

    public function test_a_single_file_plan_writes_one_file(): void
    {
        $result = $this->install($this->plan());

        self::assertTrue($result['ok']);
        self::assertCount(1, $result['steps']);
        self::assertFileDoesNotExist($this->dir . '/stream.conf');
    }

    public function test_existing_configs_are_backed_up_before_being_overwritten(): void
    {
        file_put_contents($this->dir . '/vhosts.conf', "# vhosts v1\n");
        file_put_contents($this->dir . '/stream.conf', "# splitter v1\n");

        $result = $this->install($this->plan($this->dir . '/stream.conf'));

        self::assertCount(2, $result['backups']);
        foreach ($result['backups'] as $live => $backup) {
            self::assertFileExists($backup);
            self::assertStringContainsString('v1', (string) file_get_contents($backup));
            self::assertStringContainsString('v2', (string) file_get_contents($live));
        }
    }

    public function test_a_failed_validation_rolls_BOTH_files_back(): void
    {
        // This is the case the split created: half a rollback leaves a splitter
        // and a vhost set that describe different topologies.
        file_put_contents($this->dir . '/vhosts.conf', "# vhosts v1\n");
        file_put_contents($this->dir . '/stream.conf', "# splitter v1\n");

        $result = $this->install($this->plan($this->dir . '/stream.conf'));
        $steps  = $this->restore($result['backups']);

        self::assertCount(2, $steps);
        self::assertSame("# vhosts v1\n", file_get_contents($this->dir . '/vhosts.conf'));
        self::assertSame("# splitter v1\n", file_get_contents($this->dir . '/stream.conf'));
        foreach ($result['backups'] as $backup) {
            self::assertFileDoesNotExist($backup);   // the rename consumed it
        }
    }

    public function test_a_write_that_cannot_complete_reports_the_path(): void
    {
        $plan = new EdgePlan(
            new ServerStack(true, true, true, true, true),
            Strategy::NginxStream, [], [],
            $this->dir . '/vhosts.conf', "# ok\n",
            false,
            '/proc/nonexistent-hkm-edge/stream.conf', "# nope\n",
        );

        $result = $this->install($plan);

        self::assertFalse($result['ok']);
        // The message names what actually blocked it, so the operator can fix it.
        self::assertStringContainsString('/proc/nonexistent-hkm-edge', $result['message']);
        // The first file was already written, and its backup is handed back so
        // the caller can put it right.
        self::assertArrayHasKey('backups', $result);
    }
}
