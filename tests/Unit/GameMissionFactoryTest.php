<?php

namespace Tests\Unit;

use OGame\Factories\GameMissionFactory;
use OGame\GameMissions\Abstracts\GameMission;
use OGame\GameMissions\EspionageMission;
use OGame\GameMissions\ExpeditionMission;
use OGame\GameMissions\MissileMission;
use RuntimeException;
use Tests\TestCase;

/**
 * Pins the mission id-to-class map so a split between the static catalogue
 * (`getMissionClasses`) and the instantiated list (`getAllMissions`) cannot
 * silently drop a mission. Metadata readers use the class map without building
 * a player; the instantiated list must stay the same keys.
 */
class GameMissionFactoryTest extends TestCase
{
    public function test_mission_classes_and_instances_share_the_same_keys(): void
    {
        $classes = GameMissionFactory::getMissionClasses();
        $instances = GameMissionFactory::getAllMissions();

        $this->assertSame(array_keys($classes), array_keys($instances));
        $this->assertSame([1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 15], array_keys($classes));
    }

    public function test_static_metadata_is_readable_from_the_class_map(): void
    {
        $classes = GameMissionFactory::getMissionClasses();

        $this->assertSame(EspionageMission::class, $classes[6]);
        $this->assertSame(MissileMission::class, $classes[10]);
        $this->assertSame(ExpeditionMission::class, $classes[15]);

        // Static metadata must be reachable from a class-string with no
        // instantiation: the whole point of the split is avoiding the player
        // build a mission constructor triggers.
        $this->assertIsArray($classes[6]::getRequiredResearch());
        $this->assertIsArray($classes[6]::getRequiredShipMachineNames());
        $this->assertSame(6, $classes[6]::getTypeId());
    }

    public function test_an_unknown_mission_id_throws_instead_of_falling_through(): void
    {
        $this->expectException(RuntimeException::class);

        GameMissionFactory::getMissionById(999, []);
    }

    public function test_resolved_missions_are_instances_of_the_game_mission_base(): void
    {
        foreach (GameMissionFactory::getAllMissions() as $mission) {
            $this->assertInstanceOf(GameMission::class, $mission);
        }
    }
}
