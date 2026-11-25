<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Release;

/**
 * What one update cycle installs ({@see ReleaseUpdateCycle}): a signed set release ({@see SignedReleaseCandidate}) or a
 * batch of package releases ({@see \Larena\UpdateClient\Packages\PackageBatchCandidate}). The cycle orders the steps;
 * the candidate only says how much disk it needs, checks itself, and lays out its new release folder.
 *
 * - {@see diskBytes()} — for the preflight (free space at least three times this);
 * - {@see prepare()} — every check, before anything is written to the site: a failure is a RuntimeException with a
 *   stable reason code and the cycle ends `blocked` (no copy, no maintenance);
 * - {@see lay()} — writes `<site>/releases/<release id>` and its shared links; the running release is not touched.
 */
interface CycleCandidate
{
    public function diskBytes(string $siteRoot): int;

    /**
     * @param string $from        the release `current` points to
     * @param string $fromVersion the version it runs
     */
    public function prepare(string $siteRoot, string $from, string $fromVersion): PreparedRelease;

    public function lay(string $siteRoot, PreparedRelease $prepared): void;
}
