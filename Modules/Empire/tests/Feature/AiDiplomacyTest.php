<?php

namespace Modules\Empire\Tests\Feature;

use Illuminate\Foundation\Application;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\AI\Actions\AdvanceAiAlliancePoliticsAction;
use Modules\AI\Contracts\AllianceTreaties;
use Modules\AI\Domain\Decision\Enemies;
use Modules\AI\Domain\Social\SocialMinds;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Infrastructure\Diplomacy\AiAllianceRelationTreaties;
use Modules\AI\Infrastructure\Diplomacy\EmpireAllianceTreaties;
use Modules\AI\Models\AiAllianceRelation;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiRelationship;
use Modules\Empire\Enums\TreatyStance;
use Modules\Empire\Models\AllianceTreaty;
use Modules\Empire\Services\DiplomacyService;
use Nwidart\Modules\Facades\Module;
use OGame\Models\Alliance;
use OGame\Models\Highscore;
use OGame\Models\User;
use OGame\Services\AllianceService;
use OGame\Services\ModuleSlotService;
use Tests\IsolatedAccountTestCase;

/** With Empire on, the AI's alliance politics read and write the diplomacy board humans use: one record, one rule set. */
class AiDiplomacyTest extends IsolatedAccountTestCase
{
    private string $statusesFile;

    public function createApplication(): Application
    {
        $trackedFile = dirname(__DIR__, 4) . '/modules_statuses.json';
        $statuses = json_decode((string) file_get_contents($trackedFile), true);
        $statuses['Empire'] = true;
        $this->statusesFile = sys_get_temp_dir() . '/modules_statuses_' . uniqid('', true) . '.json';
        file_put_contents($this->statusesFile, json_encode($statuses, JSON_PRETTY_PRINT));
        putenv('MODULES_STATUSES_FILE=' . $this->statusesFile);

        return parent::createApplication();
    }

    protected function setUp(): void
    {
        ModuleSlotService::resetSlots();
        parent::setUp();
        if (Module::find('AI')?->isEnabled() !== true) {
            $this->markTestSkipped('The AI module is not enabled.');
        }
    }

    protected function tearDown(): void
    {
        ModuleSlotService::resetSlots();
        if (is_file($this->statusesFile)) {
            unlink($this->statusesFile);
        }
        putenv('MODULES_STATUSES_FILE');
        parent::tearDown();
    }

    private function aiAlliance(int $playerId, string $tag): Alliance
    {
        AiProfile::query()->create([
            'player_id' => $playerId,
            'archetype' => AiArchetype::Fleeter,
            'skill_band' => AiSkillBand::Standard,
            'random_seed' => 1,
            'enabled' => true,
        ]);

        return app(AllianceService::class)->createAlliance($playerId, $tag . substr(uniqid(), -3), $tag . ' Club ' . uniqid());
    }

    private function humanAlliance(): Alliance
    {
        return app(AllianceService::class)->createAlliance(User::factory()->create()->id, 'H' . substr(uniqid(), -5), 'Humans ' . uniqid());
    }

    private function member(int $allianceId): User
    {
        $member = User::factory()->create();
        User::query()->whereKey($member->id)->update(['alliance_id' => $allianceId]);

        return $member;
    }

    private function grudge(int $holderId, int $otherId): void
    {
        AiRelationship::unguarded(fn () => AiRelationship::query()->create([
            'player_id' => $holderId,
            'other_player_id' => $otherId,
            'threat' => '1.0000',
            'trust' => '-1.0000',
            'revision' => 0,
        ]));
    }

    public function test_an_ai_war_is_written_to_the_diplomacy_board(): void
    {
        $this->assertInstanceOf(EmpireAllianceTreaties::class, app(AllianceTreaties::class));
        $ours = $this->aiAlliance($this->currentUserId, 'O');
        $mate = $this->member((int) $ours->id);
        $theirs = $this->humanAlliance();
        $raider = $this->member((int) $theirs->id);
        $this->grudge($this->currentUserId, $raider->id);
        $this->grudge($mate->id, $raider->id);

        $this->assertSame(1, app(AdvanceAiAlliancePoliticsAction::class)->handle());
        $this->assertSame(TreatyStance::War, app(DiplomacyService::class)->stance((int) $theirs->id, (int) $ours->id));
        $this->assertSame(0, AiAllianceRelation::query()->count(), 'the module keeps no second record');
        $this->assertArrayHasKey($raider->id, app(Enemies::class)->of($mate->id));
        $this->assertSame(0, app(AdvanceAiAlliancePoliticsAction::class)->handle(), 'the war it wrote is the war it reads');
    }

    public function test_an_ai_leader_turning_on_its_partner_cancels_on_the_board_and_spares_it_until_the_notice_runs_out(): void
    {
        $ours = $this->aiAlliance($this->currentUserId, 'O');
        // Seed 2 draws a leader whose taste betrays an outgrown partner (alliance-ambition.yaml).
        AiProfile::query()->where('player_id', $this->currentUserId)->update(['random_seed' => 2]);
        $theirs = $this->humanAlliance();
        $member = $this->member((int) $theirs->id);
        $diplomacy = app(DiplomacyService::class);
        $diplomacy->offerPact((int) $ours->id, (int) $theirs->id, $this->currentUserId);
        $diplomacy->acceptPact((int) $theirs->id, (int) $ours->id);
        Highscore::query()->updateOrCreate(['player_id' => $this->currentUserId], ['general' => 10_000]);
        Highscore::query()->updateOrCreate(['player_id' => $member->id], ['general' => 1_000]);

        app(AdvanceAiAlliancePoliticsAction::class)->handle();

        $this->assertTrue($diplomacy->inNotice((int) $ours->id, (int) $theirs->id));
        $this->assertTrue($diplomacy->inPact((int) $ours->id, (int) $theirs->id));
        $this->assertContains($member->id, app(Enemies::class)->spared($this->currentUserId), 'no fleet flies at a partner under notice');

        $this->travel(49)->hours();
        app(AdvanceAiAlliancePoliticsAction::class)->handle();

        $this->assertTrue($diplomacy->atWar((int) $ours->id, (int) $theirs->id));
        $this->assertNotContains($member->id, app(Enemies::class)->spared($this->currentUserId));
        $this->assertSame(0, $diplomacy->pactsBroken((int) $ours->id), 'a cancel by notice is not on the record');
    }

    public function test_two_ai_alliances_sign_an_accepted_pact_on_the_board(): void
    {
        $ours = $this->aiAlliance($this->currentUserId, 'O');
        $partner = $this->aiAlliance(User::factory()->create()->id, 'P');
        $enemy = $this->humanAlliance();
        $diplomacy = app(DiplomacyService::class);
        $diplomacy->declareWar((int) $ours->id, (int) $enemy->id, $this->currentUserId);
        $diplomacy->declareWar((int) $partner->id, (int) $enemy->id, (int) $partner->founder_user_id);

        $this->assertSame(1, app(AdvanceAiAlliancePoliticsAction::class)->handle());
        $this->assertTrue($diplomacy->inPact((int) $ours->id, (int) $partner->id));
    }

    public function test_a_pact_a_human_signs_on_the_board_binds_the_ai(): void
    {
        $ours = $this->aiAlliance($this->currentUserId, 'O');
        $theirs = $this->humanAlliance();
        $this->grudge($this->currentUserId, (int) $theirs->founder_user_id);
        $diplomacy = app(DiplomacyService::class);
        $diplomacy->offerPact((int) $theirs->id, (int) $ours->id, (int) $theirs->founder_user_id);
        $diplomacy->acceptPact((int) $ours->id, (int) $theirs->id);

        $this->assertNotContains((int) $theirs->founder_user_id, array_keys(app(Enemies::class)->of($this->currentUserId)));
        $this->assertContains((int) $theirs->founder_user_id, app(Enemies::class)->spared($this->currentUserId));
    }

    public function test_a_war_a_human_declares_on_the_board_is_the_ais_war(): void
    {
        $ours = $this->aiAlliance($this->currentUserId, 'O');
        $theirs = $this->humanAlliance();
        app(DiplomacyService::class)->declareWar((int) $theirs->id, (int) $ours->id, (int) $theirs->founder_user_id);

        $this->assertArrayHasKey((int) $theirs->founder_user_id, app(Enemies::class)->of($this->currentUserId));
    }

    /**
     * The social gateway faked as in SocialMindsPoliticsTest: PsychSim takes any pact, and CBRKit recalls pacts that
     * ended in betrayal only for an offerer on record for at least two broken pacts.
     */
    private function minds(): void
    {
        config(['ai.cognition.social.minds' => true]);
        Http::fake(static fn (Request $request) => str_ends_with($request->url(), '/health')
            ? Http::response(['status' => 'ok'])
            : Http::response(['results' => array_map(static fn (array $item): array => ['results' => [match ($item['engine'] . $item['route']) {
                'psychsim/social/partner' => ['accept_pact' => true, 'betray_now' => false, 'help_now' => false, 'p_loyal' => 0.8],
                'fatima/social/exchange' => ['choice' => 'Ignore'],
                'cbrkit/social/similar' => ['cases' => ($item['body']['items'][0]['situation']['their_broken'] ?? 0) >= 2 ? array_fill(0, 3, ['outcome' => ['label' => 'betrayed']]) : []],
                default => [],
            }]], $request->data()['items'])]));
    }

    /** A human-led alliance on record for breaking $count pacts with throwaway alliances. */
    private function breaker(int $count): Alliance
    {
        $breaker = $this->humanAlliance();
        $diplomacy = app(DiplomacyService::class);
        for ($i = 0; $i < $count; $i++) {
            $victim = $this->humanAlliance();
            $diplomacy->offerPact((int) $breaker->id, (int) $victim->id, (int) $breaker->founder_user_id);
            $diplomacy->acceptPact((int) $victim->id, (int) $breaker->id);
            $diplomacy->breakPact((int) $breaker->id, (int) $victim->id, (int) $breaker->founder_user_id);
            $diplomacy->endWar((int) $breaker->id, (int) $victim->id);
        }

        return $breaker;
    }

    public function test_the_ai_accepts_a_pact_a_human_offers_on_the_tab(): void
    {
        $ours = $this->aiAlliance($this->currentUserId, 'O');
        $theirs = $this->humanAlliance();
        $diplomacy = app(DiplomacyService::class);
        $diplomacy->offerPact((int) $theirs->id, (int) $ours->id, (int) $theirs->founder_user_id);

        $this->assertSame(1, app(AdvanceAiAlliancePoliticsAction::class)->handle());
        $this->assertTrue($diplomacy->inPact((int) $ours->id, (int) $theirs->id));
        $this->assertSame([], app(AllianceTreaties::class)->pactOffersTo((int) $ours->id));
    }

    public function test_with_minds_the_courted_leader_accepts_a_good_offer_and_declines_a_serial_pact_breaker(): void
    {
        $this->minds();
        $ours = $this->aiAlliance($this->currentUserId, 'O');
        $good = $this->humanAlliance();
        $breaker = $this->breaker(3);
        $diplomacy = app(DiplomacyService::class);
        $diplomacy->offerPact((int) $good->id, (int) $ours->id, (int) $good->founder_user_id);
        $diplomacy->offerPact((int) $breaker->id, (int) $ours->id, (int) $breaker->founder_user_id);

        $this->assertSame(2, app(AdvanceAiAlliancePoliticsAction::class)->handle());
        $this->assertTrue($diplomacy->inPact((int) $ours->id, (int) $good->id));
        $this->assertFalse($diplomacy->inPact((int) $ours->id, (int) $breaker->id));
        $this->assertSame([], app(AllianceTreaties::class)->pactOffersTo((int) $ours->id), 'the breaker\'s offer is declined, not left standing');
        Http::assertSent(static fn (Request $request): bool => collect((array) ($request->data()['items'] ?? []))
            ->contains(static fn (array $item): bool => $item['route'] === '/social/similar' && ($item['body']['items'][0]['situation']['their_broken'] ?? null) === 3));
    }

    public function test_an_ai_pact_offer_to_a_human_stands_on_the_tab(): void
    {
        $ours = $this->aiAlliance($this->currentUserId, 'O');
        $theirs = $this->humanAlliance();
        $enemy = $this->humanAlliance();
        $diplomacy = app(DiplomacyService::class);
        $diplomacy->declareWar((int) $ours->id, (int) $enemy->id, $this->currentUserId);
        $diplomacy->declareWar((int) $theirs->id, (int) $enemy->id, (int) $theirs->founder_user_id);

        $this->assertSame(1, app(AdvanceAiAlliancePoliticsAction::class)->handle());
        $this->assertSame([(int) $ours->id], app(AllianceTreaties::class)->pactOffersTo((int) $theirs->id));
        $this->assertFalse($diplomacy->inPact((int) $ours->id, (int) $theirs->id), 'the human has not answered yet');
        $this->assertSame(0, app(AdvanceAiAlliancePoliticsAction::class)->handle(), 'the offer is made once and awaited');

        $this->assertNull($diplomacy->acceptPact((int) $theirs->id, (int) $ours->id));
        $this->assertTrue($diplomacy->inPact((int) $ours->id, (int) $theirs->id));
    }

    public function test_with_empire_off_the_ai_neither_reads_nor_writes_tab_offers(): void
    {
        $this->app->instance(AllianceTreaties::class, new AiAllianceRelationTreaties());
        $treaties = app(AllianceTreaties::class);
        $ours = $this->aiAlliance($this->currentUserId, 'O');
        $theirs = $this->humanAlliance();
        $enemy = $this->humanAlliance();
        $treaties->openWar((int) $ours->id, (int) $enemy->id, 'test', $this->currentUserId);
        $treaties->openWar((int) $theirs->id, (int) $enemy->id, 'test', (int) $theirs->founder_user_id);
        $suitor = $this->humanAlliance();
        app(DiplomacyService::class)->offerPact((int) $suitor->id, (int) $ours->id, (int) $suitor->founder_user_id);

        $this->assertSame(1, app(AdvanceAiAlliancePoliticsAction::class)->handle(), 'the offer is the message, as before');
        $this->assertSame(0, AllianceTreaty::query()->where('alliance_id', $ours->id)->count());
        $this->assertSame(1, AllianceTreaty::query()->where('alliance_id', $suitor->id)->whereNull('accepted_at')->whereNull('ended_at')->count());
        $this->assertFalse($treaties->related((int) $ours->id, (int) $suitor->id));
    }

    public function test_with_minds_a_courted_human_finds_the_offer_on_the_tab(): void
    {
        config(['ai.cognition.social.minds' => true]);
        Http::fake(static fn (Request $request) => str_ends_with($request->url(), '/health')
            ? Http::response(['status' => 'ok'])
            : Http::response(['results' => array_map(static fn (array $item): array => ['results' => [$item['engine'] . $item['route'] === 'fatima/social/exchange' ? ['choice' => 'OfferPact'] : []]], $request->data()['items'])]));
        $ours = $this->aiAlliance($this->currentUserId, 'O');
        $theirs = $this->humanAlliance();
        $minds = app(SocialMinds::class);
        $minds->put($this->currentUserId, 'recalled', [(int) $theirs->founder_user_id => ['grievance' => 0.0, 'gratitude' => 1.0]]);
        $minds->save();

        app(AdvanceAiAlliancePoliticsAction::class)->handle();

        $this->assertSame([(int) $ours->id], app(AllianceTreaties::class)->pactOffersTo((int) $theirs->id));
        $this->assertNull(app(DiplomacyService::class)->acceptPact((int) $theirs->id, (int) $ours->id));
        $this->assertTrue(app(DiplomacyService::class)->inPact((int) $ours->id, (int) $theirs->id));
    }
}
