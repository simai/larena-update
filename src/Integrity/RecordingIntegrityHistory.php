<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Integrity;

/** Keeps the integrity events in memory: tests, and a site without the audit package. */
final class RecordingIntegrityHistory implements IntegrityHistory
{
    /** @var list<array{event:string,actor:string,facts:array<string,scalar|null>}> */
    public array $events = [];

    public function record(string $event, string $actor, array $facts = []): void
    {
        $this->events[] = ['event' => $event, 'actor' => $actor, 'facts' => $facts];
    }

    /** @return list<string> */
    public function types(): array
    {
        return array_map(static fn (array $event): string => $event['event'], $this->events);
    }
}
