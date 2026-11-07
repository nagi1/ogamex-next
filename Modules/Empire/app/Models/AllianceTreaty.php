<?php

namespace Modules\Empire\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Empire\Enums\TreatyStance;

/**
 * @property int $id
 * @property int $alliance_id
 * @property int $other_alliance_id
 * @property TreatyStance $stance
 * @property string $cause
 * @property int|null $declared_by
 * @property Carbon $started_at
 * @property Carbon|null $accepted_at
 * @property int|null $peace_offered_by
 * @property Carbon|null $ended_at
 * @property int|null $broken_by_alliance_id
 * @property Carbon|null $notice_ends_at
 * @property int|null $cancelled_by_alliance_id
 */
#[Fillable([
    'alliance_id', 'other_alliance_id', 'stance', 'cause', 'declared_by', 'started_at', 'accepted_at',
    'peace_offered_by', 'ended_at', 'broken_by_alliance_id', 'notice_ends_at', 'cancelled_by_alliance_id',
])]
#[Table(name: 'empire_alliance_treaties')]
class AllianceTreaty extends Model
{
    /**
     * Open rows between two alliances, either direction.
     *
     * @param Builder<AllianceTreaty> $query
     * @return Builder<AllianceTreaty>
     */
    #[Scope]
    protected function openBetween(Builder $query, int $a, int $b): Builder
    {
        return $query->whereNull('ended_at')->where(function (Builder $q) use ($a, $b): void {
            $q->where(fn (Builder $x) => $x->where('alliance_id', $a)->where('other_alliance_id', $b))
                ->orWhere(fn (Builder $x) => $x->where('alliance_id', $b)->where('other_alliance_id', $a));
        });
    }

    /**
     * Open rows that involve one alliance.
     *
     * @param Builder<AllianceTreaty> $query
     * @return Builder<AllianceTreaty>
     */
    #[Scope]
    protected function openFor(Builder $query, int $allianceId): Builder
    {
        return $query->whereNull('ended_at')
            ->where(fn (Builder $q) => $q->where('alliance_id', $allianceId)->orWhere('other_alliance_id', $allianceId));
    }

    /** The other side, seen from $allianceId. */
    public function counterpart(int $allianceId): int
    {
        return $this->alliance_id === $allianceId ? $this->other_alliance_id : $this->alliance_id;
    }

    protected function casts(): array
    {
        return [
            'stance' => TreatyStance::class,
            'started_at' => 'datetime',
            'accepted_at' => 'datetime',
            'ended_at' => 'datetime',
            'notice_ends_at' => 'datetime',
        ];
    }
}
