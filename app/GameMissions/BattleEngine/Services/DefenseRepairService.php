<?php

namespace OGame\GameMissions\BattleEngine\Services;

use OGame\GameObjects\Models\Enums\GameObjectType;
use OGame\GameObjects\Models\Units\UnitCollection;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Service responsible for calculating which destroyed defenses get repaired after battle.
 *
 * According to official game rules, approximately 70% of destroyed defenses are
 * automatically repaired and restored to the defender's planet after battle.
 */
class DefenseRepairService
{
    /**
     * @var int The repair rate percentage (0-100).
     */
    private int $repairRate;

    /**
     * @var Randomizer|null The generator the last seeded repair drew from, its own rather than PHP's global one.
     */
    private Randomizer|null $random = null;

    /**
     * DefenseRepairService constructor.
     *
     * @param int $repairRate The percentage chance (0-100) for each destroyed defense to be repaired.
     * @param int|null $seed Optional seed for deterministic testing.
     */
    public function __construct(int $repairRate = 70, private int|null $seed = null)
    {
        // Clamp repair rate to valid range
        $this->repairRate = max(0, min(100, $repairRate));
    }

    /**
     * Set seed for deterministic testing.
     *
     * @param int|null $seed
     * @return void
     */
    public function setSeed(int|null $seed): void
    {
        $this->seed = $seed;
    }

    /**
     * Calculate repaired defenses from destroyed defense units.
     * Each destroyed defense unit has a {repairRate}% chance of being repaired.
     *
     * Only defense units (GameObjectType::Defense) are processed. Ships are ignored.
     *
     * @param UnitCollection $destroyedDefenses The collection of destroyed units (may contain ships and defenses).
     * @return UnitCollection The units that were repaired (only defense units).
     */
    public function calculateRepairedDefenses(UnitCollection $destroyedDefenses): UnitCollection
    {
        $repairedDefenses = new UnitCollection();

        // If repair rate is 0, return empty collection
        if ($this->repairRate === 0) {
            return $repairedDefenses;
        }

        // A seeded repair draws from its own generator, never from PHP's global one.
        $this->random = $this->seed === null ? null : new Randomizer(new Mt19937($this->seed));

        foreach ($destroyedDefenses->units as $entry) {
            // Only process defense units, skip ships
            if ($entry->unitObject->type !== GameObjectType::Defense) {
                continue;
            }

            // Skip if no units were destroyed
            if ($entry->amount <= 0) {
                continue;
            }

            $repairedCount = 0;

            // If repair rate is 100%, repair all units
            if ($this->repairRate === 100) {
                $repairedCount = $entry->amount;
            } else {
                // For each destroyed unit, roll the dice to see if it gets repaired
                for ($i = 0; $i < $entry->amount; $i++) {
                    $roll = $this->random !== null ? $this->random->getInt(1, 100) : random_int(1, 100);
                    if ($roll <= $this->repairRate) {
                        $repairedCount++;
                    }
                }
            }

            // Handle shield dome edge case: can only have 1 of each type
            if ($this->isShieldDome($entry->unitObject->machine_name)) {
                $repairedCount = min($repairedCount, 1);
            }

            if ($repairedCount > 0) {
                $repairedDefenses->addUnit($entry->unitObject, $repairedCount);
            }
        }

        return $repairedDefenses;
    }

    /**
     * The seeded generator the last repair drew from, so the battle's later draws can continue it; null when the
     * repair was not seeded or its rate is zero.
     */
    public function randomizer(): Randomizer|null
    {
        return $this->random;
    }

    /**
     * Check if a unit is a shield dome (can only have 1 per planet).
     *
     * @param string $machineName
     * @return bool
     */
    private function isShieldDome(string $machineName): bool
    {
        return in_array($machineName, ['small_shield_dome', 'large_shield_dome']);
    }
}
