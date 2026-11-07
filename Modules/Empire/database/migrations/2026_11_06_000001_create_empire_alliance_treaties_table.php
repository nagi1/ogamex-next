<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        // One row per war or pact between two alliances. A pact row with accepted_at null is an offer awaiting the other
        // side; a war row with peace_offered_by set awaits the other side's acceptance of peace. ended_at closes a row,
        // and broken_by_alliance_id records who broke a pact: the public reputation every leader can read.
        Schema::create('empire_alliance_treaties', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('alliance_id');
            $table->unsignedBigInteger('other_alliance_id');
            $table->unsignedTinyInteger('stance');
            $table->string('cause', 64);
            $table->unsignedBigInteger('declared_by')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('accepted_at')->nullable();
            $table->unsignedBigInteger('peace_offered_by')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedBigInteger('broken_by_alliance_id')->nullable();
            $table->timestamps();
            $table->index(['alliance_id', 'ended_at'], 'empire_treaties_open');
            $table->index(['other_alliance_id', 'ended_at'], 'empire_treaties_other_open');
            $table->index('broken_by_alliance_id', 'empire_treaties_broken_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('empire_alliance_treaties');
    }
};
