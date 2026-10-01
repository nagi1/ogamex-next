<?php

/**
 * Plants the inactive neighbours a real universe has from its first week: accounts that built an
 * ordinary mine economy and then stopped logging in. Without them a fresh cohort has nothing a raid
 * is worth flying for (the host calls a player inactive when `users.time` is older than 7 days), so
 * the raid ladder can never open and the raids aspect reads zero for a reason that is not the AI's.
 *
 * DEV TOOLING for a cohort universe, never shipped behaviour. Accounts register through the host's
 * own path; only their building levels and last-activity time are written afterwards. Their stock
 * then accrues through the host's own production on the next update, capped by their storage.
 *
 * Run inside a cohort app container, after the human first account and the AI accounts exist:
 *   php /var/www/local-docker-dev/seed-inactive-neighbours.php <count>
 */

require '/var/www/vendor/autoload.php';
$app = require '/var/www/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Str;
use OGame\Actions\Fortify\CreateNewUser;
use OGame\Models\Planet;
use OGame\Models\User;

$count = max(1, (int) ($argv[1] ?? 10));
$quitAt = now()->subDays(10)->getTimestamp();

for ($index = 1; $index <= $count; $index++) {
    $email = "inactive-{$index}@example.com";
    $user = User::query()->where('email', $email)->first()
        ?? app(CreateNewUser::class)->create(['email' => $email, 'password' => Str::password(32)]);

    // A spread of what ordinary quitters leave behind: a mid-teens metal mine, the storage a week of
    // play affords, and on every other account a token defence a raider has to price in.
    $levels = [
        'metal_mine' => 12 + $index % 6,
        'crystal_mine' => 10 + $index % 5,
        'deuterium_synthesizer' => 6 + $index % 4,
        'solar_plant' => 14 + $index % 5,
        'robot_factory' => 2,
        'shipyard' => 2,
        'research_lab' => 1,
        'metal_store' => 3 + $index % 3,
        'crystal_store' => 2 + $index % 3,
        'deuterium_store' => 1 + $index % 3,
    ];
    $defence = $index % 2 === 0 ? ['rocket_launcher' => 10 * $index, 'light_laser' => 2 * $index] : [];

    foreach (Planet::query()->where('user_id', $user->id)->get() as $planet) {
        $planet->forceFill([...$levels, ...$defence,
            'field_current' => array_sum($levels),
            'time_last_update' => $quitAt,
        ])->save();
    }

    $user->forceFill(['time' => $quitAt])->save();
    printf("inactive %d: user %d (%s)\n", $index, $user->id, $user->username);
}
