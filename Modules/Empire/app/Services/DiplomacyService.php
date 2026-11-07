<?php

namespace Modules\Empire\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Modules\Empire\Enums\TreatyStance;
use Modules\Empire\Models\AllianceTreaty;
use OGame\Models\Alliance;

/**
 * Diplomacy between alliances: the one place wars, pacts, peace and betrayals are written, for players (the alliance
 * page) and the AI module alike. Each method returns null when it acted, or the translation key of why it refused.
 *
 * - War: either side declares; one war at a time per pair.
 * - Pact (non-aggression): one side offers, the other accepts; an open war blocks it.
 * - Peace: a side at war offers peace, the other accepts; the war row closes.
 * - Notice: a side cancels an accepted pact; it binds NOTICE_HOURS more, then closes and that side is at war.
 * - Betrayal: a side breaks an accepted pact without notice; the pact closes with the breaker recorded and a war opens
 *   at once.
 * - Reputation: how many pacts an alliance has broken without notice, readable by everyone. A cancel by notice is not
 *   on the record (FOREIGN-MINISTRY-REPEATED-GAMES M5: the surprise break is the taboo).
 */
class DiplomacyService
{
    /** A cancelled pact binds this long (the AI module's social-minds.yaml `pact_notice_hours`). */
    public const NOTICE_HOURS = 48;

    public function stance(int $a, int $b): TreatyStance|null
    {
        $row = AllianceTreaty::query()->openBetween($a, $b)
            ->where(fn ($q) => $q->where('stance', TreatyStance::War)->orWhereNotNull('accepted_at'))
            ->orderByDesc('id')->first();

        return $row?->stance;
    }

    public function atWar(int $a, int $b): bool
    {
        return $this->stance($a, $b) === TreatyStance::War;
    }

    public function inPact(int $a, int $b): bool
    {
        return $this->stance($a, $b) === TreatyStance::Pact;
    }

    /** Pacts this alliance has broken: its public reputation. */
    public function pactsBroken(int $allianceId): int
    {
        return AllianceTreaty::query()->where('broken_by_alliance_id', $allianceId)->count();
    }

    public function declareWar(int $allianceId, int $otherId, int|null $byUserId, string $cause = 'declared'): string|null
    {
        if ($refusal = $this->pair($allianceId, $otherId)) {
            return $refusal;
        }
        if ($this->atWar($allianceId, $otherId)) {
            return 't_empire.refusal.already_at_war';
        }
        if ($this->inPact($allianceId, $otherId)) {
            return 't_empire.refusal.break_pact_first';
        }

        DB::transaction(function () use ($allianceId, $otherId, $byUserId, $cause): void {
            // A pending pact offer between the two lapses when war is declared.
            AllianceTreaty::query()->openBetween($allianceId, $otherId)->whereNull('accepted_at')
                ->where('stance', TreatyStance::Pact)->update(['ended_at' => now()]);
            $this->open($allianceId, $otherId, TreatyStance::War, $cause, $byUserId, accepted: true);
        });

        return null;
    }

    public function offerPact(int $allianceId, int $otherId, int|null $byUserId, string $cause = 'offered'): string|null
    {
        if ($refusal = $this->pair($allianceId, $otherId)) {
            return $refusal;
        }
        if ($this->atWar($allianceId, $otherId)) {
            return 't_empire.refusal.make_peace_first';
        }
        if (AllianceTreaty::query()->openBetween($allianceId, $otherId)->where('stance', TreatyStance::Pact)->exists()) {
            return 't_empire.refusal.pact_exists';
        }
        $this->open($allianceId, $otherId, TreatyStance::Pact, $cause, $byUserId, accepted: false);

        return null;
    }

    /** The receiving side accepts a pact offered to it. */
    public function acceptPact(int $allianceId, int $offererId): string|null
    {
        $offer = AllianceTreaty::query()->whereNull('ended_at')->whereNull('accepted_at')->where('stance', TreatyStance::Pact)
            ->where('alliance_id', $offererId)->where('other_alliance_id', $allianceId)->first();
        if ($offer === null) {
            return 't_empire.refusal.no_offer';
        }
        if ($this->atWar($allianceId, $offererId)) {
            return 't_empire.refusal.make_peace_first';
        }
        $offer->update(['accepted_at' => now()]);

        return null;
    }

    /** The receiving side turns a pact offer down. */
    public function declinePact(int $allianceId, int $offererId): string|null
    {
        $updated = AllianceTreaty::query()->whereNull('ended_at')->whereNull('accepted_at')->where('stance', TreatyStance::Pact)
            ->where('alliance_id', $offererId)->where('other_alliance_id', $allianceId)->update(['ended_at' => now()]);

        return $updated > 0 ? null : 't_empire.refusal.no_offer';
    }

    /** Betrayal: the pact closes with $allianceId on record as its breaker, and war opens at once. */
    public function breakPact(int $allianceId, int $otherId, int|null $byUserId): string|null
    {
        $pact = AllianceTreaty::query()->openBetween($allianceId, $otherId)->where('stance', TreatyStance::Pact)
            ->whereNotNull('accepted_at')->first();
        if ($pact === null) {
            return 't_empire.refusal.no_pact';
        }

        DB::transaction(function () use ($pact, $allianceId, $otherId, $byUserId): void {
            $pact->update(['ended_at' => now(), 'broken_by_alliance_id' => $allianceId]);
            $this->open($allianceId, $otherId, TreatyStance::War, 'betrayal', $byUserId, accepted: true);
        });

        return null;
    }

    /** Notice: the pact binds until $endsAt (NOTICE_HOURS from now by default), then endNotices opens the war. */
    public function cancelPact(int $allianceId, int $otherId, int|null $byUserId, CarbonInterface|null $endsAt = null): string|null
    {
        $updated = AllianceTreaty::query()->openBetween($allianceId, $otherId)->where('stance', TreatyStance::Pact)
            ->whereNotNull('accepted_at')->whereNull('notice_ends_at')
            ->update(['notice_ends_at' => $endsAt ?? now()->addHours(self::NOTICE_HOURS), 'cancelled_by_alliance_id' => $allianceId]);

        return $updated > 0 ? null : 't_empire.refusal.no_pact';
    }

    public function inNotice(int $a, int $b): bool
    {
        return AllianceTreaty::query()->openBetween($a, $b)->where('stance', TreatyStance::Pact)->whereNotNull('notice_ends_at')->exists();
    }

    /**
     * Each pact whose notice has run out closes, and the side that cancelled is at war with the other.
     *
     * @return list<array{0: int, 1: int}> the side that cancelled and the other side of each
     */
    public function endNotices(): array
    {
        $ended = [];
        foreach (AllianceTreaty::query()->whereNull('ended_at')->where('stance', TreatyStance::Pact)->where('notice_ends_at', '<=', now())->orderBy('id')->get() as $pact) {
            $canceller = (int) $pact->cancelled_by_alliance_id;
            $other = $pact->counterpart($canceller);
            DB::transaction(function () use ($pact, $canceller, $other): void {
                $pact->update(['ended_at' => now()]);
                $this->open($canceller, $other, TreatyStance::War, 'betrayal', null, accepted: true);
            });
            $ended[] = [$canceller, $other];
        }

        return $ended;
    }

    public function offerPeace(int $allianceId, int $otherId): string|null
    {
        $war = $this->war($allianceId, $otherId);
        if ($war === null) {
            return 't_empire.refusal.not_at_war';
        }
        $war->update(['peace_offered_by' => $allianceId]);

        return null;
    }

    /** The other side accepts a peace offer: the war ends. */
    public function acceptPeace(int $allianceId, int $otherId): string|null
    {
        $war = $this->war($allianceId, $otherId);
        if ($war === null || $war->peace_offered_by !== $otherId) {
            return 't_empire.refusal.no_offer';
        }
        $war->update(['ended_at' => now()]);

        return null;
    }

    /** Ends a war without a peace offer (the AI's grievances faded, or an admin closed it). */
    public function endWar(int $allianceId, int $otherId): string|null
    {
        $war = $this->war($allianceId, $otherId);
        if ($war === null) {
            return 't_empire.refusal.not_at_war';
        }
        $war->update(['ended_at' => now()]);

        return null;
    }

    private function war(int $a, int $b): AllianceTreaty|null
    {
        return AllianceTreaty::query()->openBetween($a, $b)->where('stance', TreatyStance::War)->first();
    }

    private function pair(int $a, int $b): string|null
    {
        if ($a === $b) {
            return 't_empire.refusal.own_alliance';
        }

        return Alliance::query()->whereKey([$a, $b])->count() === 2 ? null : 't_empire.refusal.no_alliance';
    }

    private function open(int $a, int $b, TreatyStance $stance, string $cause, int|null $by, bool $accepted): AllianceTreaty
    {
        return AllianceTreaty::query()->create([
            'alliance_id' => $a,
            'other_alliance_id' => $b,
            'stance' => $stance,
            'cause' => substr($cause, 0, 64),
            'declared_by' => $by,
            'started_at' => now(),
            'accepted_at' => $accepted ? now() : null,
        ]);
    }
}
