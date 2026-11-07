<?php

namespace Modules\Empire\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;
use OGame\Services\ModuleSlotService;

/**
 * Alliance empires: the diplomacy board on the alliance page (wars, pacts, peace, betrayal and the public record of who
 * broke a pact). Players use it from the alliance page; the AI module uses `DiplomacyService` the same way.
 */
class EmpireServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Empire';

    protected string $nameLower = 'empire';

    protected array $providers = [
        RouteServiceProvider::class,
    ];

    public function boot(): void
    {
        parent::boot();

        ModuleSlotService::register('alliance.tabs', static function (array $data): string {
            return view('empire::partials.alliance-tab')->render();
        });
    }
}
