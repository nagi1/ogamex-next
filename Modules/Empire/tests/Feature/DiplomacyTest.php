<?php

namespace Modules\Empire\Tests\Feature;

use Illuminate\Foundation\Application;
use Modules\Empire\Enums\TreatyStance;
use Modules\Empire\Services\DiplomacyService;
use OGame\Models\User;
use OGame\Services\AllianceService;
use OGame\Services\ModuleSlotService;
use Tests\IsolatedAccountTestCase;

/** The diplomacy board: wars, pacts, peace, betrayal and the reputation it leaves, and the tab on the alliance page. */
class DiplomacyTest extends IsolatedAccountTestCase
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

    /** @return array{0: int, 1: int} our alliance (founded by the current user) and another one */
    private function twoAlliances(): array
    {
        $alliances = app(AllianceService::class);
        $ours = $alliances->createAlliance($this->currentUserId, 'E' . substr(uniqid(), -5), 'Empire ' . uniqid());
        $theirs = $alliances->createAlliance(User::factory()->create()->id, 'F' . substr(uniqid(), -5), 'Foe ' . uniqid());

        return [(int) $ours->id, (int) $theirs->id];
    }

    public function test_pact_offer_acceptance_betrayal_and_reputation(): void
    {
        [$a, $b] = $this->twoAlliances();
        $d = app(DiplomacyService::class);

        $this->assertNull($d->offerPact($a, $b, $this->currentUserId));
        $this->assertNull($d->stance($a, $b), 'an offer is not yet a pact');
        $this->assertSame('t_empire.refusal.no_offer', $d->acceptPact($a, $b), 'only the receiving side accepts');
        $this->assertNull($d->acceptPact($b, $a));
        $this->assertTrue($d->inPact($a, $b));
        $this->assertSame('t_empire.refusal.break_pact_first', $d->declareWar($a, $b, null));

        $this->assertNull($d->breakPact($a, $b, $this->currentUserId));
        $this->assertTrue($d->atWar($a, $b), 'a broken pact is war at once');
        $this->assertSame(1, $d->pactsBroken($a));
        $this->assertSame(0, $d->pactsBroken($b), 'the betrayed side keeps its name');
    }

    public function test_a_pact_cancelled_by_notice_binds_until_it_runs_out_and_leaves_no_stain(): void
    {
        [$a, $b] = $this->twoAlliances();
        $d = app(DiplomacyService::class);
        $d->offerPact($a, $b, $this->currentUserId);
        $d->acceptPact($b, $a);

        $this->assertNull($d->cancelPact($a, $b, $this->currentUserId));
        $this->assertSame('t_empire.refusal.no_pact', $d->cancelPact($a, $b, $this->currentUserId), 'one notice per pact');
        $this->assertTrue($d->inPact($a, $b) && $d->inNotice($b, $a), 'a cancelled pact binds through its notice');
        $this->travel(DiplomacyService::NOTICE_HOURS - 1)->hours();
        $this->assertSame([], $d->endNotices());
        $this->assertTrue($d->inPact($a, $b));

        $this->travel(2)->hours();
        $this->assertSame([[$a, $b]], $d->endNotices());
        $this->assertTrue($d->atWar($b, $a), 'the side that cancelled is at war once the notice runs out');
        $this->assertSame(0, $d->pactsBroken($a), 'a cancel by notice is no betrayal');
    }

    public function test_war_ends_only_when_the_other_side_accepts_peace(): void
    {
        [$a, $b] = $this->twoAlliances();
        $d = app(DiplomacyService::class);

        $this->assertNull($d->declareWar($a, $b, $this->currentUserId));
        $this->assertSame(TreatyStance::War, $d->stance($b, $a));
        $this->assertSame('t_empire.refusal.make_peace_first', $d->offerPact($b, $a, null));
        $this->assertNull($d->offerPeace($a, $b));
        $this->assertSame('t_empire.refusal.no_offer', $d->acceptPeace($a, $b), 'the offerer cannot accept its own offer');
        $this->assertNull($d->acceptPeace($b, $a));
        $this->assertNull($d->stance($a, $b));
    }

    public function test_the_founder_sees_the_tab_and_declares_war_from_it(): void
    {
        [$a, $b] = $this->twoAlliances();

        $this->get('/alliance')->assertOk()->assertSeeHtml(route('empire.diplomacy.tab'));
        $this->get('/ajax/empire/diplomacy')->assertOk()->assertSee(__('t_empire.act.war'));
        $this->post('/empire/diplomacy/war', ['other_alliance_id' => $b])->assertRedirect(route('alliance.index'));
        $this->assertTrue(app(DiplomacyService::class)->atWar($a, $b));
    }
}
