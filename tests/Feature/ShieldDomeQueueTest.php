<?php

namespace Tests\Feature;

use OGame\Models\Resources;
use OGame\Models\UnitQueue;
use OGame\Services\ObjectService;
use Tests\IsolatedAccountTestCase;

/**
 * A shield dome can be built once per planet, and a dome still in the shipyard queue counts as that one.
 */
class ShieldDomeQueueTest extends IsolatedAccountTestCase
{
    private function basicSetup(): void
    {
        $this->planetSetObjectLevel('robot_factory', 2);
        $this->planetSetObjectLevel('shipyard', 6);
        $this->playerSetResearchLevel('energy_technology', 3);
        $this->playerSetResearchLevel('shielding_technology', 6);
        $this->planetAddResources(new Resources(10000000, 10000000, 10000000, 0));
    }

    public function testQueuedDomeBlocksASecondOrder(): void
    {
        $this->basicSetup();

        foreach (['small_shield_dome', 'large_shield_dome'] as $dome) {
            $this->assertEquals(1, ObjectService::getObjectMaxBuildAmount($dome, $this->planetService, true), $dome . ' is buildable once');

            $this->addDefenseBuildRequest($dome, 1);
            $this->addDefenseBuildRequest($dome, 1);

            $queued = UnitQueue::where('planet_id', $this->planetService->getPlanetId())
                ->where('object_id', ObjectService::getObjectByMachineName($dome)->id)
                ->where('processed', 0)
                ->sum('object_amount');
            $this->assertEquals(1, $queued, 'A second ' . $dome . ' order is refused while the first is queued');
            $this->assertEquals(0, ObjectService::getObjectMaxBuildAmount($dome, $this->planetService, true));
        }
    }
}
