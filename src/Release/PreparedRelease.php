<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Release;

/**
 * A checked candidate of a cycle: the release folder it will become, whether its migrations stop a code-only rollback,
 * and the facts the journal keeps about it (`kind`, the packages of a batch). `subject` is the candidate's own checked
 * object (the verified release, the checked batch plan), for {@see CycleCandidate::lay()} only.
 */
final readonly class PreparedRelease
{
    /** @param array<string, scalar|null> $facts */
    public function __construct(
        public string $releaseId,
        public bool $backwardIncompatibleMigrations,
        public array $facts,
        public object $subject,
    ) {
        ReleaseDirectory::id($releaseId);
    }
}
