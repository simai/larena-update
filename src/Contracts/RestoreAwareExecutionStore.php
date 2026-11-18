<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Contracts;

/**
 * A store that lives in the database a rollback restores (the site's own `update_cycles` table). A restore from the
 * cycle's copy brings the table back as it was when the copy was made — the running cycle still `planned`, later
 * cycles gone — and the open connection may still point at the database file that was replaced. The cycle therefore
 * takes {@see history()} right before a restore and hands it to {@see reinstate()} right after it: the history of
 * updates is never rolled back with the site data.
 */
interface RestoreAwareExecutionStore extends UpdateExecutionStore
{
    /** @return list<array<string,int|string>> every stored cycle as it is now */
    public function history(): array;

    /**
     * Reconnects to the restored database and puts every cycle of `$history` back exactly as it was.
     *
     * @param list<array<string,int|string>> $history
     */
    public function reinstate(array $history): void;
}
