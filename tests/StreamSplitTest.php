<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Edge;

use PHPUnit\Framework\TestCase;
use Plugins\Edge\Domain\CacheProfile;
use Plugins\Edge\Domain\EdgePlan;
use Plugins\Edge\Domain\ServeModel;
use Plugins\Edge\Domain\ServerStack;
use Plugins\Edge\Domain\Site;
use Plugins\Edge\Domain\Strategy;
use Plugins\Edge\Domain\TlsConfig;
use Plugins\Edge\Domain\TlsMode;
use Plugins\Edge\Infrastructure\ConfigRenderer;

/**
 * The SNI-splitter strategy — the one that runs when nginx AND Apache are both
 * up — used to render `stream {}` and `server {}` into ONE file. nginx accepts
 * that nowhere: at the main context it refuses `server`, inside `http {}` it
 * refuses `stream`. The strategy could not produce a loadable config at all.
 *
 * These tests pin the split, because the two halves are only correct TOGETHER
 * and nothing else in the suite would notice one of them going missing.
 */
final class StreamSplitTest extends TestCase
{
    /** @return list<Site> */
    private function sites(): array
    {
        return [new Site(
            name: 'shop',
            docroot: '/srv/shop/app/public',
            publicDomains: ['shop.example'],
            localDomains: [],
            model: ServeModel::Fpm,
            upstream: 'unix:/run/php/php8.4-fpm.sock',
            env: ['APP_ENV' => 'production'],
            root: '/srv/shop',
        )];
    }

    private function stack(bool $existingSplitter = false): ServerStack
    {
        // nginx + Apache both active, nginx has the stream module.
        return new ServerStack(true, true, true, true, true, false, [], $existingSplitter);
    }

    /** @return array{0: array{0: string, 1: string}, 1: array{0: string, 1: string}|null} */
    private function render(bool $reuse = false): array
    {
        $renderer = new ConfigRenderer();
        $sites    = $this->sites();

        return [
            $renderer->render(Strategy::NginxStream, $sites,
                new TlsConfig(TlsMode::Ssl, '/tmp/c.pem', '/tmp/k.pem'),
                $this->stack($reuse), CacheProfile::Production, $reuse),
            $renderer->renderStream(Strategy::NginxStream, $sites, $reuse),
        ];
    }

    public function test_the_vhost_file_holds_no_stream_block(): void
    {
        [[$path, $body]] = $this->render();

        // A block, not the word — the banner legitimately says "`stream {}`".
        self::assertDoesNotMatchRegularExpression('/^stream \s*\{/m', $body);
        self::assertStringContainsString('server {', $body);
        // It is an ordinary http-context vhost file, so it goes where they go.
        self::assertStringEndsWith('hkm-edge-nginx.conf', $path);
        self::assertStringContainsString('INCLUDE THIS FILE INSIDE `http', $body);
    }

    public function test_the_splitter_is_its_own_main_context_file(): void
    {
        [, $stream] = $this->render();

        self::assertNotNull($stream);
        [$path, $body] = $stream;
        self::assertStringEndsWith('hkm-edge-stream.conf', $path);
        self::assertMatchesRegularExpression('/^stream \s*\{/m', $body);
        self::assertStringContainsString('ssl_preread on;', $body);
        // No `server` block outside the stream block: everything after the
        // closing brace would be at the main context, where it is illegal.
        self::assertSame(1, substr_count($body, 'server {'));   // the stream server
        self::assertStringEndsWith("}\n", $body);
    }

    public function test_the_two_files_are_written_to_different_paths(): void
    {
        [[$vhostPath], $stream] = $this->render();

        self::assertNotNull($stream);
        self::assertNotSame($vhostPath, $stream[0]);
    }

    public function test_reusing_an_existing_splitter_writes_only_the_vhosts(): void
    {
        [[, $body], $stream] = $this->render(reuse: true);

        // Edge must never emit a SECOND splitter next to the host's own.
        self::assertNull($stream);
        self::assertDoesNotMatchRegularExpression('/^stream \s*\{/m', $body);
        self::assertStringContainsString('REUSING it', $body);
    }

    public function test_the_vhost_file_carries_exactly_one_managed_banner(): void
    {
        [[, $body]] = $this->render();

        self::assertSame(1, substr_count($body, 'Managed by the HKM Edge plugin'));
    }

    public function test_a_plan_lists_every_file_it_installs(): void
    {
        [[$path, $body], $stream] = $this->render();

        $split = new EdgePlan($this->stack(), Strategy::NginxStream, $this->sites(), [], $path, $body,
            false, $stream[0], $stream[1]);
        self::assertSame([$path, $stream[0]], array_keys($split->files()));

        $single = new EdgePlan($this->stack(), Strategy::NginxOnly, $this->sites(), [], $path, $body);
        self::assertSame([$path], array_keys($single->files()));
    }
}
