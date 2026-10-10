<?php

namespace OGame\Services;

use OGame\Factories\PlayerServiceFactory;

/**
 * Advances the player-owned state normally refreshed by an in-game request.
 *
 * Scheduled game actors use this instead of constructing an HTTP request or
 * bypassing due queue and fleet processing.
 */
class PlayerGameStateService
{
    public function __construct(private PlayerServiceFactory $playerServiceFactory)
    {
    }

    public function advance(int $playerId, int|null $currentPlanetId = null, bool $markActivity = true): PlayerService
    {
        $player = $this->playerServiceFactory->make($playerId, true);

        if ($currentPlanetId !== null) {
            $player->setCurrentPlanetId($currentPlanetId);
        }

        if ($markActivity) {
            $player->update();
        }
        if (!$markActivity) {
            $player->updateResearchQueue(false);
        }
        if ($player->planets->all() !== []) {
            $player->planets->current()->update();
        }
        $player->updateFleetMissions();

        return $player;
    }
}
