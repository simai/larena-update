<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Laravel;

use Larena\UpdateClient\Solutions\SolutionRights;
use Throwable;

/**
 * The rights of a solution's packages from larena/licensing, read only (matrix 35): `SiteLicensing::allows()` for the
 * capability; for a missing one, «Попробовать» from the shipped capability catalog (a paid, trial-eligible function and
 * its trial length, 14 days unless the catalog says otherwise) and «Купить» and the title from the addons «Дополнения»
 * shows (`SiteAddons`: the addon that carries the capability, else the shop of its package or of the site). Without
 * larena/licensing in the site nothing is allowed and nothing is offered: the site cannot tell.
 */
final readonly class SiteSolutionRights implements SolutionRights
{
    private const TRIAL_DAYS = 14;

    /**
     * @param object|null $licensing \Larena\Licensing\Site\SiteLicensing
     * @param object|null $catalog   \Larena\Licensing\Catalog\CapabilityCatalog
     * @param object|null $addons    \Larena\Licensing\Site\SiteAddons
     */
    public function __construct(private ?object $licensing, private ?object $catalog = null, private ?object $addons = null)
    {
    }

    public function allows(string $capability): bool
    {
        if (!class_exists(\Larena\Licensing\Site\SiteLicensing::class) || !$this->licensing instanceof \Larena\Licensing\Site\SiteLicensing) {
            return false;
        }
        try {
            return $this->licensing->allows($capability);
        } catch (Throwable) {
            return false;
        }
    }

    public function offer(string $capability, string $package): array
    {
        $trial = false;
        $days = null;
        if (class_exists(\Larena\Licensing\Catalog\CapabilityCatalog::class) && $this->catalog instanceof \Larena\Licensing\Catalog\CapabilityCatalog) {
            $entry = $this->catalog->get($capability);
            if ($entry !== null && $entry->trialEligible && !$entry->isFree()) {
                $trial = true;
                $days = $entry->trialDays ?? self::TRIAL_DAYS;
            }
        }
        $title = null;
        $shop = null;
        if (class_exists(\Larena\Licensing\Site\SiteAddons::class) && $this->addons instanceof \Larena\Licensing\Site\SiteAddons) {
            try {
                foreach ($this->addons->list()['addons'] as $addon) {
                    if (in_array($capability, $addon['capabilities'], true)) {
                        $title = $addon['title'];
                        $shop = $addon['shop_url'];
                        break;
                    }
                }
                $shop ??= $this->addons->shopFor($package);
            } catch (Throwable) {
                // No addon list: «Купить» has no address; the refusal still names the capability.
            }
        }

        return ['title' => $title, 'trial' => $trial, 'trial_days' => $days, 'shop_url' => $shop];
    }
}
