<?php

namespace OGame\Factories;

use OGame\GameMissions\Abstracts\GameMission;
use OGame\GameMissions\AcsDefendMission;
use OGame\GameMissions\AttackMission;
use OGame\GameMissions\ColonisationMission;
use OGame\GameMissions\DeploymentMission;
use OGame\GameMissions\EspionageMission;
use OGame\GameMissions\ExpeditionMission;
use OGame\GameMissions\MissileMission;
use OGame\GameMissions\MoonDestructionMission;
use OGame\GameMissions\RecycleMission;
use OGame\GameMissions\TransportMission;
use RuntimeException;

class GameMissionFactory
{
    /**
     * The id-to-class map, without instantiation. A GameMission constructor pulls
     * FleetMissionService and MessageService, and both require a PlayerService, so
     * resolving a mission builds a player for metadata that is already static
     * (getTypeId, getRequiredResearch, getRequiredShipMachineNames). Readers that
     * only need that static metadata iterate this map and call the class directly.
     *
     * @return array<int, class-string<GameMission>>
     */
    public static function getMissionClasses(): array
    {
        /*
        {
          "1": "Attack",
          "2": "ACS Attack",
          "3": "Transport",
          "4": "Deployment",
          "5": "ACS Defend",
          "6": "Espionage",
          "7": "Colonisation",
          "8": "Recycle Debris Field",
          "9": "Moon Destruction",
          "15": "Expedition"
        }
        */
        return [
            1 => AttackMission::class,
            2 => AttackMission::class,
            3 => TransportMission::class,
            4 => DeploymentMission::class,
            5 => AcsDefendMission::class,
            6 => EspionageMission::class,
            7 => ColonisationMission::class,
            8 => RecycleMission::class,
            9 => MoonDestructionMission::class,
            10 => MissileMission::class,
            15 => ExpeditionMission::class,
        ];
    }

    /**
     * @return array<GameMission>
     */
    public static function getAllMissions(): array
    {
        return array_map(
            static fn (string $class): GameMission => resolve($class),
            self::getMissionClasses(),
        );
    }

    /**
     * @param int $missionId
     * @param array<string,mixed> $dependencies
     *
     * @return GameMission
     */
    public static function getMissionById(int $missionId, array $dependencies): GameMission
    {
        $class = self::getMissionClasses()[$missionId] ?? null;

        if ($class === null) {
            throw new RuntimeException('Mission not found: ' . $missionId);
        }

        return resolve($class, $dependencies);
    }
}
