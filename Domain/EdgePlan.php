<?php

declare(strict_types=1);

namespace Plugins\Edge\Domain;

/**
 * The full result of planning an edge apply: the detected stack, the chosen
 * strategy, the per-project Sites that fed the config, the dev-only local
 * domains (→ /etc/hosts), and the rendered file(s) — see files().
 */
final readonly class EdgePlan
{
    /**
     * @param list<Site>   $sites        per-project sites in the server config
     * @param list<string> $localDomains dev-only domains (.local / .test / …) → /etc/hosts
     */
    public function __construct(
        public ServerStack $stack,
        public Strategy $strategy,
        public array $sites,
        public array $localDomains,
        public string $targetPath,
        public string $contents,
        // NginxStream only: an existing nginx stream splitter was found and is
        // being reused, so no splitter file of our own is written.
        public bool $reuseStream = false,
        /**
         * NginxStream only: the MAIN-context `stream {}` splitter, which is a
         * SEPARATE file from $targetPath because nginx will not accept `stream`
         * and `server` in one included file — main rejects the second, http the
         * first. Null whenever no splitter of our own is written.
         */
        public ?string $streamPath = null,
        public ?string $streamContents = null,
    ) {}

    /**
     * Every file this plan installs, in the order they should be written:
     * [path => contents]. One entry for the single-server strategies, two when
     * an SNI splitter is written alongside its backend vhosts.
     *
     * @return array<string, string>
     */
    public function files(): array
    {
        $files = [];
        if ($this->targetPath !== '') {
            $files[$this->targetPath] = $this->contents;
        }
        if ($this->streamPath !== null && $this->streamPath !== '') {
            $files[$this->streamPath] = (string) $this->streamContents;
        }

        return $files;
    }
}
