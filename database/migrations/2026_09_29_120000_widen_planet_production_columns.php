<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widen the per-hour production columns, which cannot hold what a fast universe produces.
 *
 * `planets.metal_production` and its crystal and deuterium siblings were plain signed `int`, so they
 * top out at 2,147,483,647 per hour. Production is stored as the raw hourly rate, and that rate is the
 * mine's output multiplied by the universe's economy speed: on a 90,000x universe an ordinary level-30
 * mine already computes 3.8-4.1 billion per hour, and the update that stores it fails with
 * "Out of range value for column 'metal_production'" — which is not a cosmetic failure, it aborts the
 * whole planet update, so the planet's queues stop advancing and its resources stop accruing.
 *
 * `bigint` keeps the value the host already computes, so nothing else changes. Resources themselves are
 * `double` and were never the problem.
 */
return new class extends Migration
{
    /** @var array<int, string> */
    private const COLUMNS = ['metal_production', 'crystal_production', 'deuterium_production'];

    public function up(): void
    {
        Schema::table('planets', function (Blueprint $table): void {
            foreach (self::COLUMNS as $column) {
                $table->bigInteger($column)->default(0)->change();
            }
        });
    }

    public function down(): void
    {
        Schema::table('planets', function (Blueprint $table): void {
            foreach (self::COLUMNS as $column) {
                $table->integer($column)->default(0)->change();
            }
        });
    }
};
