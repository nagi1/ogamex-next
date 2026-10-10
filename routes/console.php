<?php

use OGame\Console\Commands\Scheduler\CleanupDestroyedPlanets;
use OGame\Console\Commands\Scheduler\CleanupWreckFields;
use OGame\Console\Commands\Scheduler\DarkMatterRegenerateCommand;
use OGame\Console\Commands\Scheduler\DeleteInactivePlayers;
use OGame\Console\Commands\Scheduler\DeleteOldMessages;
use OGame\Console\Commands\Scheduler\GenerateAllianceHighscores;
use OGame\Console\Commands\Scheduler\GenerateHighscoreRanks;
use OGame\Console\Commands\Scheduler\GenerateHighscores;
use OGame\Console\Commands\Scheduler\ProcessFleetArrivals;
use OGame\Console\Commands\Scheduler\ProcessPlanetQueues;
use OGame\Console\Commands\Scheduler\ResetDebrisFields;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of your Closure based console
| commands. Each Closure is bound to a command instance allowing a
| simple approach to interacting with each command's IO methods.
|
*/

Schedule::command(GenerateHighscores::class)->everyFiveMinutes();
// Alliance highscores should run after player highscores since they depend on them
Schedule::command(GenerateAllianceHighscores::class)->everyFiveMinutes();
// Generates ranks for both player and alliance highscores
Schedule::command(GenerateHighscoreRanks::class)->everyFiveMinutes();

// Reset empty debris fields weekly on Monday at 1:00 AM
Schedule::command(ResetDebrisFields::class)->weeklyOn(1, '1:00');

// Clean up wreck fields hourly
Schedule::command(CleanupWreckFields::class)->hourly()->withoutOverlapping();

// Catch up any fleet arrivals missed while the queue worker or server was down.
Schedule::command(ProcessFleetArrivals::class)->everyMinute()->withoutOverlapping();

// Advance building, unit and research queues on planets that have work due. Queue progress used to
// depend on somebody opening the planet: a universe nobody visits (a cohort of AI accounts) sat with
// 8,958 building and 11,451 unit orders waiting for a page load that never came. Ten seconds keeps
// the wait invisible while the cohorts run at speed multipliers that make work instant anyway.
// The lock expires in five minutes: `withoutOverlapping()` defaults to 1440, so a run killed
// mid-tick would block its own command for a day -- a scheduler that can lock itself out.
Schedule::command(ProcessPlanetQueues::class)->everyTenSeconds()->withoutOverlapping(5);

// Delete messages once they have aged out of the seven-day retention window
Schedule::command(DeleteOldMessages::class)->hourly()->withoutOverlapping();

// Permanently delete destroyed planets/moons flagged for at least 24 hours (official 3:00 cycle)
Schedule::command(CleanupDestroyedPlanets::class)->dailyAt('03:00')->withoutOverlapping();

// Process Dark Matter regeneration every 5 minutes
Schedule::command(DarkMatterRegenerateCommand::class)->everyFiveMinutes()->withoutOverlapping();

// Delete players that have been inactive beyond the configured threshold (0 = disabled)
Schedule::command(DeleteInactivePlayers::class)->daily()->withoutOverlapping();

// Record Horizon metrics (used by the dashboard's workload graphs). Only meaningful
// while the Redis queue backend, which Horizon requires, is the active connection.
if (config('queue.default') === 'redis') {
    Schedule::command('horizon:snapshot')->everyFiveMinutes();
}
