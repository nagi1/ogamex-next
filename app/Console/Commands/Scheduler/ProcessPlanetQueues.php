<?php

namespace OGame\Console\Commands\Scheduler;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use OGame\Factories\PlanetServiceFactory;
use OGame\Services\BuildingQueueService;
use OGame\Services\DarkMatterService;
use OGame\Services\FleetMissionService;
use OGame\Services\PlanetMoveService;
use OGame\Services\ResearchQueueService;
use OGame\Services\SettingsService;
use OGame\Services\UnitQueueService;
use Throwable;

/**
 * Drive every waiting queue forward, without anyone loading a page.
 *
 * The host advances queues from two places, both of them a page load: `GlobalGame` middleware touches
 * the planet on a request, and `PlanetService::update()` completes whatever is finished. That leaves a
 * universe nobody visits — a cohort of AI accounts, a bot-driven universe — permanently idle, and it
 * breaks in two distinct ways:
 *
 *   1. **A head that was never started never starts.** `BuildingQueueService::retrieveFinished()` only
 *      returns rows with `building = 1`, and `ResearchQueueService` asks the same. A row is started by
 *      `start()`, which runs from `add()` or from the completion of the previous item in the chain.
 *      Break that chain — a queue emptied by hand, a completed build whose successor was inserted while
 *      something else was running — and the order sits at `time_start = 0` forever. Measured on the
 *      grand cohort: 122 building orders queued at zero duration, none of them started.
 *   2. **Work that is finished is never applied.** Levels, ships and research land only inside
 *      `PlanetService::update()`, so on the same cohort 11,451 unit orders had finished and been paid
 *      for but never granted.
 *
 * So each tick starts the heads that are waiting and then runs the same update a page load would, which
 * completes what is due and chains the next item behind it. Nothing here re-implements queue
 * arithmetic: `start()` and `update()` are the host's own.
 */
#[Description('Start and complete planet queue work, so no page load is needed for progress.')]
#[Signature('ogamex:scheduler:process-planet-queues {--limit=0 : Maximum planets per step (0 = all)} {--chunk=300 : Planets considered per batch} {--idle-seconds=60 : Also refresh planets not updated for this long (0 = only planets with queue work)}')]
class ProcessPlanetQueues extends Command
{
    public function handle(
        PlanetServiceFactory $planets,
        BuildingQueueService $buildingQueue,
        ResearchQueueService $researchQueue,
        PlanetMoveService $planetMoves,
    ): int {
        $now = now()->timestamp;
        $chunk = max(1, (int) $this->option('chunk'));
        $limit = (int) $this->option('limit');

        $due = $this->duePlanets($now, $chunk);
        $unstarted = $this->unstartedHeads();
        $idle = $this->stalePlanets((int) $this->option('idle-seconds'), $chunk);

        $planetIds = array_keys($due + $unstarted + $idle);
        if ($limit > 0) {
            $planetIds = array_slice($planetIds, 0, $limit);
        }

        $started = 0;
        $advanced = 0;
        $failed = 0;

        foreach ($planetIds as $planetId) {
            try {
                $planet = $planets->make($planetId, true);
                if ($planet === null) {
                    continue;
                }

                // A head that is waiting for someone to press go: this is the call a page load would
                // make for it. Both services no-op when something is already running.
                if (isset($unstarted[$planetId])) {
                    $buildingQueue->start($planet);
                    $player = $planet->getPlayer();
                    if ($player !== null) {
                        $researchQueue->start($player);
                    }
                    $started++;
                }

                if (isset($due[$planetId]) || isset($idle[$planetId])) {
                    // The host's own page-load path: completes finished work, applies it, chains the
                    // next item, and repeats while more is finished.
                    $planet->update();
                    $advanced++;
                }
            } catch (Throwable $error) {
                // One broken planet must not stall the universe; the next tick retries it.
                $failed++;
                $this->error("planet {$planetId}: {$error->getMessage()}");
            }
        }

        if ($planetIds !== []) {
            $this->info(sprintf('started %d, advanced %d, failed %d planet(s)', $started, $advanced, $failed));
        }

        // Relocation used to complete only for a player who happened to load a page while their move
        // was in transit, so a move in an unvisited universe never arrived. The service looks up which
        // moves are due, so this costs one indexed query when none are.
        $planetMoves->processDueMoves(
            $planets,
            app(DarkMatterService::class),
            app(SettingsService::class),
            $buildingQueue,
            $researchQueue,
            app(UnitQueueService::class),
            app(FleetMissionService::class),
        );

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Planets with work that is finished, or that never started, and so can move this tick.
     *
     * @return array<int, true>
     */
    private function duePlanets(int $now, int $chunk): array
    {
        $planets = [];

        foreach (['building_queues', 'unit_queues', 'research_queues'] as $table) {
            $query = DB::table($table)
                ->where('processed', 0)
                ->where(function ($inner) use ($now): void {
                    $inner->where('time_end', '<=', $now)->orWhere('time_end', 0);
                });

            if ($table !== 'unit_queues') {
                $query->where('canceled', 0);
            }

            foreach ($query->distinct()->limit($chunk)->pluck('planet_id') as $planetId) {
                $planets[(int) $planetId] = true;
            }
        }

        return $planets;
    }

    /**
     * Planets nobody has looked at recently.
     *
     * Resource production is credited inside `updateResources()`, which a page load calls: without this
     * a planet with an empty queue produces nothing at all until somebody opens it, which is the same
     * page-load dependency in a quieter form.
     *
     * @return array<int, true>
     */
    private function stalePlanets(int $idleSeconds, int $chunk): array
    {
        if ($idleSeconds <= 0) {
            return [];
        }

        $planets = [];

        foreach (DB::table('planets')->where('time_last_update', '<', now()->timestamp - $idleSeconds)
            ->orderBy('time_last_update')->limit($chunk)->pluck('id') as $planetId) {
            $planets[(int) $planetId] = true;
        }

        return $planets;
    }

    /**
     * Planets whose queue holds a head that was never started.
     *
     * `time_start = 0` with `building = 0` is exactly the state `BuildingQueueService::start()` looks
     * for, and nothing else in the host ever starts it.
     *
     * @return array<int, true>
     */
    private function unstartedHeads(): array
    {
        $planets = [];

        foreach (['building_queues', 'research_queues'] as $table) {
            $rows = DB::table($table)
                ->where('processed', 0)
                ->where('canceled', 0)
                ->where('building', 0)
                ->where('time_start', 0)
                ->distinct()
                ->pluck('planet_id');

            foreach ($rows as $planetId) {
                $planets[(int) $planetId] = true;
            }
        }

        return $planets;
    }
}
