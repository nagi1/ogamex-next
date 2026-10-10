<?php

namespace OGame\Contracts;

/**
 * A read-only hostile-action rule a module can register with the host's hostility guard.
 *
 * The policy only answers — it never writes host state. The host owns the enforcement: it
 * consults every registered policy at the authoritative hostile-action point and rejects the
 * action when any policy forbids it. The contract is the whole point of the seam: the host
 * cannot name a module class that may be absent, so it asks this interface instead.
 */
interface HostilityPolicy
{
    /**
     * Whether the hostile action between these two players must be rejected.
     */
    public function forbids(int $attackerPlayerId, int $defenderPlayerId): bool;
}
