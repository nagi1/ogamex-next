<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use OGame\Contracts\HostilityPolicy;
use OGame\Enums\UniverseMode;
use OGame\Services\HostilityGuard;
use OGame\Services\SettingsService;
use RuntimeException;
use Tests\TestCase;

/**
 * The guard is the host's read-only seam: an ordinary universe is never restricted, a
 * cooperative universe fails closed with no policy, and a policy that throws is treated
 * as a rejection. Registered policies are exercised directly so the module's own policy
 * never leaks into these assertions.
 */
class HostilityGuardTest extends TestCase
{
    use DatabaseTransactions;

    public function testOrdinaryUniverseNeverForbids(): void
    {
        app(SettingsService::class)->setUniverseMode(UniverseMode::Ordinary);

        $this->assertFalse((new HostilityGuard())->forbids(1, 2));
    }

    public function testCooperativeUniverseWithNoPolicyFailsClosed(): void
    {
        app(SettingsService::class)->setUniverseMode(UniverseMode::Cooperative);

        $this->assertTrue((new HostilityGuard())->forbids(1, 2));
    }

    public function testCooperativeUniverseConsultsItsRegisteredPolicy(): void
    {
        app(SettingsService::class)->setUniverseMode(UniverseMode::Cooperative);

        $guard = new HostilityGuard();
        $guard->register(new class () implements HostilityPolicy {
            public function forbids(int $attackerPlayerId, int $defenderPlayerId): bool
            {
                return $attackerPlayerId === 1;
            }
        });

        $this->assertTrue($guard->forbids(1, 2));
        $this->assertFalse($guard->forbids(3, 2));
    }

    public function testThrowingPolicyFailsClosed(): void
    {
        app(SettingsService::class)->setUniverseMode(UniverseMode::Cooperative);

        $guard = new HostilityGuard();
        $guard->register(new class () implements HostilityPolicy {
            public function forbids(int $attackerPlayerId, int $defenderPlayerId): bool
            {
                throw new RuntimeException('provider failed');
            }
        });

        $this->assertTrue($guard->forbids(1, 2));
    }

    public function testUnresolvableDefenderIsNotAssessed(): void
    {
        app(SettingsService::class)->setUniverseMode(UniverseMode::Cooperative);

        $this->assertFalse((new HostilityGuard())->forbids(1, null));
    }
}
