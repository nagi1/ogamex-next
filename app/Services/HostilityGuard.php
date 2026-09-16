<?php

namespace OGame\Services;

use OGame\Contracts\HostilityPolicy;
use Throwable;

/**
 * The host's read-only hostile-action gate for declared universes.
 *
 * An ordinary universe is never restricted: the guard returns false before any policy is
 * consulted, so normal PvP is untouched. A cooperative universe must have at least one
 * registered policy; with none — the module is absent, disabled or failed to register —
 * every hostile action is rejected, so a PvE world can never silently become PvP. A policy
 * that throws is treated as a rejection for the same reason.
 */
class HostilityGuard
{
    /** @var list<HostilityPolicy> */
    private array $policies = [];

    public function register(HostilityPolicy $policy): void
    {
        $this->policies[] = $policy;
    }

    public function forbids(int $attackerPlayerId, ?int $defenderPlayerId): bool
    {
        // An unresolvable defender has no owner to be hostile toward; the action's own
        // target checks reject it, so the guard stays out of the way.
        if ($defenderPlayerId === null) {
            return false;
        }

        if ((string) app(SettingsService::class)->get('universe_mode', 'ordinary') !== 'cooperative') {
            return false;
        }

        if ($this->policies === []) {
            return true;
        }

        foreach ($this->policies as $policy) {
            try {
                if ($policy->forbids($attackerPlayerId, $defenderPlayerId)) {
                    return true;
                }
            } catch (Throwable) {
                return true;
            }
        }

        return false;
    }
}
