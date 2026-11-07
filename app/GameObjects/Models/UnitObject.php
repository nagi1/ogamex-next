<?php

namespace OGame\GameObjects\Models;

use OGame\GameObjects\Models\Abstracts\GameObject;
use OGame\GameObjects\Models\Fields\GameObjectProperties;
use OGame\GameObjects\Models\Fields\GameObjectRapidfire;
use Random\Randomizer;

abstract class UnitObject extends GameObject
{
    /**
     * Objects that this object requires on with required level.
     *
     * @var array<GameObjectRapidfire>
     */
    public array $rapidfire = [];

    /**
     * Properties of the object.
     *
     * @var GameObjectProperties
     */
    public GameObjectProperties $properties;

    /**
     * Do rapidfire dice roll against the given target object. If current unit has rapidfire against the target
     * and the dice roll is successful, return true. Otherwise, return false.
     *
     * @param UnitObject $object
     * @param Randomizer $random The battle's seeded generator.
     * @return bool
     */
    public function didSuccessfulRapidfire(UnitObject $object, Randomizer $random): bool
    {
        foreach ($this->rapidfire as $rapidfire) {
            if ($rapidfire->object_machine_name == $object->machine_name) {
                // Rapidfire continues with probability (n - 1) / n, matching the original game.
                // For example amount 4 means 3/4 = 75% chance.
                // The battle's own generator, so a seeded battle replays identically.
                return $random->getInt(1, $rapidfire->amount) > 1;
            }
        }

        return false;
    }
}
