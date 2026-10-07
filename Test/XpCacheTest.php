<?php

require_once 'tests/units/Base.php';
require_once __DIR__ . '/ProgressFixture.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\TimeReport\Model\XpCache;
use Kanboard\Plugin\TimeReport\Model\XpModel;
use Kanboard\Plugin\TimeReport\Subscriber\ProgressSubscriber;

class XpCacheTest extends Base
{
    use ProgressFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createProgressSchema();
        $this->container['dispatcher']->addSubscriber(new ProgressSubscriber($this->container));
    }

    private function cache(): XpCache
    {
        return new XpCache($this->container);
    }

    private function queries(callable $fn): int
    {
        $h = $this->container['db']->getStatementHandler();
        $before = $h->getNbQueries();
        $fn();
        return $h->getNbQueries() - $before;
    }

    public function testColdReadBuildsThenWarmReadIsOneQuery(): void
    {
        $alice = $this->user('alice');
        $p = $this->project();
        $this->subtask($this->task($p), $alice, 2);

        $this->assertSame(['total' => 10, 'by_project' => [$p => 10], 'party_xp' => null], $this->cache()->lifetime($alice));
        $this->assertSame(1, $this->queries(fn () => $this->cache()->lifetime($alice)));
    }

    public function testCompletingASubtaskStampsAndInvalidates(): void
    {
        $alice = $this->user('alice');
        $p = $this->project();
        $s = $this->subtask($this->task($p), $alice, 0);
        $this->assertSame(0, $this->cache()->lifetime($alice)['total']);

        $this->container['subtaskModel']->update(['id' => $s, 'status' => 2]);

        $this->assertSame(1, $this->container['db']->table('timereport_subtask_completions')->eq('subtask_id', $s)->count());
        $this->assertSame(10, $this->cache()->lifetime($alice)['total']);
    }

    public function testClosingATaskStampsSubtasksCoreClosedWithoutEvents(): void
    {
        $alice = $this->user('alice');
        $p = $this->project();
        $t = $this->task($p);
        $s = $this->subtask($t, $alice, 0);
        $before = time();

        $this->container['taskStatusModel']->close($t);

        $stamp = $this->container['db']->table('timereport_subtask_completions')->eq('subtask_id', $s)->findOne();
        $this->assertNotEmpty($stamp);
        $this->assertSame($alice, (int) $stamp['user_id']);
        $this->assertSame([$p => 10], (new XpModel($this->container))->deriveUser($alice, $before - 1, time() + 1));
    }

    public function testReassignDoneSubtaskMovesXpAfterInvalidation(): void
    {
        $alice = $this->user('alice');
        $bob = $this->user('bob');
        $s = $this->subtask($this->task($this->project()), $alice, 2);
        $this->assertSame(10, $this->cache()->lifetime($alice)['total']);
        $this->assertSame(0, $this->cache()->lifetime($bob)['total']);

        $this->container['subtaskModel']->update(['id' => $s, 'user_id' => $bob]);

        $this->assertSame(0, $this->cache()->lifetime($alice)['total']);
        $this->assertSame(10, $this->cache()->lifetime($bob)['total']);
    }

    public function testReopenRemovesTaskXp(): void
    {
        $alice = $this->user('alice');
        $t = $this->task($this->project(), ['owner_id' => $alice, 'score' => 2]);
        $this->close($t);
        $this->assertSame(45, $this->cache()->lifetime($alice)['total']);

        $this->container['taskStatusModel']->open($t);

        $this->assertSame(0, $this->cache()->lifetime($alice)['total']);
    }

    public function testScoreChangeOnClosedTaskIsPickedUp(): void
    {
        $alice = $this->user('alice');
        $t = $this->task($this->project(), ['owner_id' => $alice]);
        $this->close($t);
        $this->assertSame(25, $this->cache()->lifetime($alice)['total']);

        $this->container['taskModificationModel']->update(['id' => $t, 'score' => 3]);

        $this->assertSame(55, $this->cache()->lifetime($alice)['total']);
    }

    public function testPartyXpIncludesAgentsAndIsNullWithoutThem(): void
    {
        $owner = $this->user('owner');
        $bot = $this->user('owner.claude');
        $solo = $this->user('solo');
        $this->agentsTable([$bot => $owner]);
        $p = $this->project();
        $t = $this->task($p);
        $this->subtask($t, $owner, 2);
        $this->subtask($t, $bot, 2);
        $this->subtask($t, $bot, 2);

        $this->assertSame(['total' => 10, 'by_project' => [$p => 10], 'party_xp' => 30], $this->cache()->lifetime($owner));
        $this->assertNull($this->cache()->lifetime($solo)['party_xp']);
        $this->assertNull($this->cache()->lifetime($bot)['party_xp']);
    }

    public function testInvalidateAllEmptiesTheCache(): void
    {
        $alice = $this->user('alice');
        $this->cache()->lifetime($alice);
        $this->assertGreaterThan(0, $this->container['db']->table(XpCache::TABLE)->count());

        $this->cache()->invalidateAll();

        $this->assertSame(0, $this->container['db']->table(XpCache::TABLE)->count());
    }
}
