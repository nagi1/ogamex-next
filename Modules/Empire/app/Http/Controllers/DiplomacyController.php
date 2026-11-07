<?php

namespace Modules\Empire\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Empire\Enums\TreatyStance;
use Modules\Empire\Models\AllianceTreaty;
use Modules\Empire\Services\DiplomacyService;
use OGame\Http\Controllers\OGameController;
use OGame\Models\Alliance;
use OGame\Models\AllianceRank;
use OGame\Services\AllianceService;
use OGame\Services\PlayerService;

/** The diplomacy tab on the player's alliance page, and the leader's diplomatic acts. */
class DiplomacyController extends OGameController
{
    public const ACTS = ['war', 'offer-pact', 'accept-pact', 'decline-pact', 'cancel-pact', 'break-pact', 'offer-peace', 'accept-peace'];

    public function tab(AllianceService $alliances, PlayerService $player, DiplomacyService $diplomacy): JsonResponse
    {
        $allianceId = (int) $player->getUser()->alliance_id;
        $alliance = $allianceId > 0 ? $alliances->getAllianceById($allianceId) : null;
        if ($alliance === null) {
            return response()->json(['status' => 'failure', 'message' => __('t_ingame.alliance.msg_not_in_alliance'), 'newAjaxToken' => csrf_token()], 400);
        }
        $member = $alliances->getAllianceMember($allianceId, $player->getId());
        // A notice that ran out ends here too, so the board is right in a world without AI politics ticking.
        $diplomacy->endNotices();
        $open = AllianceTreaty::query()->openFor($allianceId)->orderBy('started_at')->get();
        $others = Alliance::query()->whereKeyNot($allianceId)->orderBy('alliance_tag')->get(['id', 'alliance_tag', 'alliance_name']);
        $tags = $others->pluck('alliance_tag', 'id');
        $rows = $open->map(fn (AllianceTreaty $t): array => [
            'other' => $t->counterpart($allianceId),
            'tag' => (string) ($tags[$t->counterpart($allianceId)] ?? '?'),
            'stance' => $t->stance,
            'pending' => $t->stance === TreatyStance::Pact && $t->accepted_at === null,
            'incoming' => $t->other_alliance_id === $allianceId,
            'peace_from_them' => $t->stance === TreatyStance::War && $t->peace_offered_by === $t->counterpart($allianceId),
            'peace_from_us' => $t->stance === TreatyStance::War && $t->peace_offered_by === $allianceId,
            'since' => $t->started_at,
            'cause' => $t->cause,
            'notice' => $t->notice_ends_at,
        ]);
        $broken = AllianceTreaty::query()->whereNotNull('broken_by_alliance_id')
            ->selectRaw('broken_by_alliance_id, count(*) as n')->groupBy('broken_by_alliance_id')->pluck('n', 'broken_by_alliance_id');

        return response()->json([
            'content' => [
                'empire/diplomacy' => view('empire::diplomacy')->with([
                    'rows' => $rows,
                    'others' => $others,
                    'broken' => $broken,
                    'canAct' => $member !== null && $member->hasPermission(AllianceRank::PERMISSION_MANAGE_ALLY),
                    'ownBroken' => $diplomacy->pactsBroken($allianceId),
                ])->render(),
            ],
            'newAjaxToken' => csrf_token(),
        ]);
    }

    public function act(string $act, Request $request, AllianceService $alliances, PlayerService $player, DiplomacyService $diplomacy): RedirectResponse
    {
        $allianceId = (int) $player->getUser()->alliance_id;
        $member = $allianceId > 0 ? $alliances->getAllianceMember($allianceId, $player->getId()) : null;
        if ($member === null || !$member->hasPermission(AllianceRank::PERMISSION_MANAGE_ALLY)) {
            return redirect()->route('alliance.index')->with('error', __('t_empire.refusal.no_permission'));
        }
        $other = (int) $request->input('other_alliance_id');
        $refusal = match ($act) {
            'war' => $diplomacy->declareWar($allianceId, $other, $player->getId()),
            'offer-pact' => $diplomacy->offerPact($allianceId, $other, $player->getId()),
            'accept-pact' => $diplomacy->acceptPact($allianceId, $other),
            'decline-pact' => $diplomacy->declinePact($allianceId, $other),
            'cancel-pact' => $diplomacy->cancelPact($allianceId, $other, $player->getId()),
            'break-pact' => $diplomacy->breakPact($allianceId, $other, $player->getId()),
            'offer-peace' => $diplomacy->offerPeace($allianceId, $other),
            'accept-peace' => $diplomacy->acceptPeace($allianceId, $other),
            default => abort(404),
        };

        return $refusal === null
            ? redirect()->route('alliance.index')->with('success', __('t_empire.done.' . $act, ['hours' => DiplomacyService::NOTICE_HOURS]))
            : redirect()->route('alliance.index')->with('error', __($refusal));
    }
}
