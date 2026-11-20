<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Laravel\Integrity;

use Illuminate\Http\Request;

/**
 * The receipt of a plan the person saw («план → подтверждение», as the admin's other planned actions): the plan's
 * screen is given one; the confirmation brings it back, and only a receipt that is this person's, fresh, not used yet
 * and made for exactly this action lets the action run, with the plan's digest it holds. The run then refuses the
 * action if the plan made again has another digest (the files changed in between).
 */
final readonly class IntegrityPlanReceipts
{
    public const FIELD = 'receipt';
    private const SESSION = 'larena_update_integrity_receipts';
    private const KEEP = 10;

    public function __construct(private int $seconds = 900)
    {
    }

    public function issue(Request $request, string $action, string $digest): string
    {
        if (!$request->hasSession()) {
            return '';
        }
        $now = time();
        $id = bin2hex(random_bytes(16));
        $receipts = array_filter($this->all($request), static fn (mixed $receipt): bool => is_array($receipt) && (int) ($receipt['until'] ?? 0) >= $now);
        $receipts[$id] = ['action' => $action, 'digest' => $digest, 'actor' => self::actor($request), 'until' => $now + $this->seconds];
        $request->session()->put(self::SESSION, array_slice($receipts, -self::KEEP, null, true));

        return $id;
    }

    /** The plan digest the confirmed receipt holds, once; null for none, another person's, a used or an old one. */
    public function take(Request $request, string $action): ?string
    {
        if (!$request->hasSession()) {
            return null;
        }
        $id = $request->input(self::FIELD);
        $receipts = $this->all($request);
        if (!is_string($id) || preg_match('/\A[a-f0-9]{32}\z/D', $id) !== 1 || !isset($receipts[$id])) {
            return null;
        }
        $receipt = $receipts[$id];
        unset($receipts[$id]);
        $request->session()->put(self::SESSION, $receipts);
        if (!is_array($receipt) || ($receipt['action'] ?? null) !== $action || (int) ($receipt['until'] ?? 0) < time()
            || !is_string($receipt['actor'] ?? null) || !hash_equals($receipt['actor'], self::actor($request))
            || !is_string($receipt['digest'] ?? null)) {
            return null;
        }

        return $receipt['digest'];
    }

    public static function actor(Request $request): string
    {
        $actor = $request->attributes->get('larena_access_actor');

        return is_string($actor) ? $actor : '';
    }

    /** @return array<string,mixed> */
    private function all(Request $request): array
    {
        $receipts = $request->session()->get(self::SESSION, []);

        return is_array($receipts) ? $receipts : [];
    }
}
