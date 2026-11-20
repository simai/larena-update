<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Integrity;

/**
 * What «Вернуть как было» will do, worked out from what the checks found and checked again against the files as they
 * are now: release files written again from the kept archive (`restore`), extra PHP and `.htaccess` files moved to the
 * quarantine (`quarantine`), and what stops it (`blockers`: no trusted list, the archive missing or damaged) or what
 * it cannot do for one file (`skipped`). The confirmation carries the plan's digest: if the files changed since the
 * plan was shown, the run is refused and the plan is made again.
 */
final readonly class RestorePlan
{
    /**
     * @param list<array{path:string,kind:string}>                                 $restore
     * @param list<array{root:string,path:string,sha256:string,bytes:int,kind:string}> $quarantine
     * @param list<string>                                                          $blockers
     * @param list<array{path:string,reason:string}>                                $skipped
     */
    public function __construct(
        public string $baseline,
        public array $restore = [],
        public array $quarantine = [],
        public array $blockers = [],
        public array $skipped = [],
    ) {
    }

    public function digest(): string
    {
        return hash('sha256', (string) json_encode([
            $this->baseline,
            array_map(static fn (array $item): string => $item['path'], $this->restore),
            array_map(static fn (array $item): array => [$item['root'], $item['path'], $item['sha256']], $this->quarantine),
            $this->blockers,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function empty(): bool
    {
        return $this->restore === [] && $this->quarantine === [];
    }

    public function blocked(): bool
    {
        return $this->blockers !== [];
    }
}
