<?php

/**
 * Bootstraps Laravel inside the capacity app container, registers the one human first
 * account the seeder refuses to proceed without, and sets the grand-test speed rows.
 *
 * Run inside the capacity app container as:
 *   php /var/www/local-docker-dev/capacity-prepare.php <players>
 */

require '/var/www/vendor/autoload.php';
$app = require '/var/www/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$players = max(1, (int) ($argv[1] ?? 2));

$email = "human-cap-{$players}@example.com";

if (\OGame\Models\User::query()->where('email', $email)->doesntExist()) {
    app(\OGame\Actions\Fortify\CreateNewUser::class)->create([
        'email' => $email,
        'password' => \Illuminate\Support\Str::password(32),
    ]);
    echo "human first account registered\n";
} else {
    echo "human first account already present\n";
}

$settings = app(\OGame\Services\SettingsService::class);

foreach ([
    'economy_speed',
    'research_speed',
    'fleet_speed',
    'fleet_speed_war',
    'fleet_speed_holding',
    'fleet_speed_peaceful',
] as $key) {
    $settings->set($key, 1000);
}

echo "speed rows set to 1000x\n";
