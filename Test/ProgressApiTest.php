<?php

require_once 'tests/units/Base.php';
require_once __DIR__ . '/ProgressFixture.php';

use KanboardTests\units\Base;
use JsonRPC\Exception\AccessDeniedException;
use Kanboard\Plugin\TimeReport\Api\TimeReportProgressProcedure;
use Kanboard\Plugin\TimeReport\Api\TimeReportXpProcedure;

class ProgressApiTest extends Base
{
    use ProgressFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createProgressSchema();
    }

    private function progress(): TimeReportProgressProcedure
    {
        return new TimeReportProgressProcedure($this->container);
    }

    private function xp(): TimeReportXpProcedure
    {
        return new TimeReportXpProcedure($this->container);
    }

    private function actAs(int $uid): void
    {
        $this->container['userSession']->initialize($this->container['userModel']->getById($uid));
    }

    private function member(int $projectId, int $userId): void
    {
        $this->container['projectUserRoleModel']->addUser($projectId, $userId, \Kanboard\Core\Security\Role::PROJECT_MEMBER);
    }

    public function testTaskProgressShape(): void
    {
        $t = $this->task($this->project());
        $this->subtask($t, 1, 2);
        // After the subtask: core's SubtaskModel::create re-sums task time from subtasks.
        $this->container['db']->table('tasks')->eq('id', $t)->update(['time_estimated' => 4, 'time_spent' => 1]);

        $r = $this->progress()->getTaskProgress($t);
        $this->assertSame(1, $r['subtasks_done']);
        $this->assertSame(1, $r['subtasks_total']);
        $this->assertSame(100, $r['pct']);
        $this->assertSame('ok', $r['time_state']);
        $this->assertFalse($this->progress()->getTaskProgress(99999));
        $this->assertFalse($this->progress()->getTaskProgress('abc'));
    }

    public function testProjectAndMilestoneProgress(): void
    {
        $p = $this->project();
        $ms = $this->task($p, ['title' => 'Beta']);
        $a = $this->task($p);
        $this->milestone($ms, [$a, $this->task($p)]);
        $this->close($a);

        $r = $this->progress()->getProjectProgress($p);
        $this->assertSame(1, $r['closed']);
        $this->assertSame(3, $r['total']);
        $this->assertSame('Beta', $r['milestones'][0]['title']);

        $m = $this->progress()->getMilestoneProgress($ms);
        $this->assertSame(50, $m['pct']);
        $this->assertCount(2, $m['members']);
        $this->assertFalse($this->progress()->getMilestoneProgress($a));
    }

    public function testProjectProgressDeniedForNonMember(): void
    {
        $p = $this->project();
        $outsider = $this->user('outsider');
        $this->actAs($outsider);

        $this->expectException(AccessDeniedException::class);
        $this->progress()->getProjectProgress($p);
    }

    public function testTaskProgressDeniedForNonMember(): void
    {
        $t = $this->task($this->project());
        $this->actAs($this->user('outsider'));

        $this->expectException(AccessDeniedException::class);
        $this->progress()->getTaskProgress($t);
    }

    public function testUserXpLifetimeAndRange(): void
    {
        $alice = $this->user('alice');
        $p = $this->project();
        $t = $this->task($p, ['owner_id' => $alice, 'score' => 1]);
        $s = $this->subtask($t, $alice, 2);
        $this->close($t);
        $this->container['db']->table('tasks')->eq('id', $t)->update(['date_completed' => strtotime('2026-10-05 10:00:00')]);
        $this->container['db']->table('timereport_subtask_completions')->insert(['subtask_id' => $s, 'user_id' => $alice, 'completed_at' => strtotime('2026-09-01 10:00:00')]);

        $life = $this->xp()->getUserXp($alice);
        $this->assertSame(45, $life['xp']);
        $this->assertSame(1, $life['level']);
        $this->assertSame(55, $life['next']);
        $this->assertSame([$p => 45], $life['by_project']);
        $this->assertArrayNotHasKey('party', $life);

        $range = $this->xp()->getUserXp($alice, $p, '2026-10-01', '2026-10-31');
        $this->assertSame(35, $range['xp']);

        $this->assertFalse($this->xp()->getUserXp($alice, null, 'not-a-date', '2026-10-31'));
        $this->assertFalse($this->xp()->getUserXp(99999));
        $this->assertFalse($this->xp()->getUserXp($alice, 'abc'));
        $this->assertFalse($this->xp()->getUserXp($alice, 99999));
        $this->assertFalse($this->xp()->getUserXp($alice, null, '2026-02-30', '2026-03-31'));
        $this->assertFalse($this->xp()->getUserXp($alice, null, '2026-10-31', '2026-10-01'));
        $this->assertFalse($this->xp()->getXpLeaderboard($p, '2026-10-31', '2026-10-01'));
        // Half-open ranges are rejected, never silently widened to lifetime.
        $this->assertFalse($this->xp()->getUserXp($alice, null, '2026-10-01'));
        $this->assertFalse($this->xp()->getXpLeaderboard($p, null, '2026-10-31'));
    }

    public function testUserXpHidesInvisibleProjects(): void
    {
        $alice = $this->user('alice');
        $viewer = $this->user('viewer');
        $shared = $this->project('Shared');
        $secret = $this->project('Secret');
        $this->member($shared, $viewer);
        $this->subtask($this->task($shared), $alice, 2);
        $this->subtask($this->task($secret), $alice, 2);
        $this->actAs($viewer);

        $r = $this->xp()->getUserXp($alice);
        $this->assertSame([$shared => 10], $r['by_project']);
        $this->assertSame(10, $r['xp']);
    }

    public function testUserXpPartyForOwners(): void
    {
        $owner = $this->user('owner');
        $bot = $this->user('owner.claude');
        $this->agentsTable([$bot => $owner]);
        $t = $this->task($this->project());
        $this->subtask($t, $owner, 2);
        $this->subtask($t, $bot, 2);

        $r = $this->xp()->getUserXp($owner);
        $this->assertSame(20, $r['party']['xp']);
        $this->assertSame([['user_id' => $bot, 'xp' => 10]], $r['party']['members']);
    }

    public function testLeaderboardMarksAgents(): void
    {
        $owner = $this->user('owner');
        $bot = $this->user('owner.claude');
        $this->agentsTable([$bot => $owner]);
        $p = $this->project();
        $t = $this->task($p);
        $this->subtask($t, $bot, 2);
        $this->subtask($t, $bot, 2);
        $this->subtask($t, $owner, 2);

        $this->assertSame([
            ['user_id' => $bot, 'xp' => 20, 'level' => 1, 'is_agent' => true, 'owner_id' => $owner],
            ['user_id' => $owner, 'xp' => 10, 'level' => 1, 'is_agent' => false, 'owner_id' => 0],
        ], $this->xp()->getXpLeaderboard($p));
    }

    public function testUserXpCountsClosedProjectsForMember(): void
    {
        $alice = $this->user('alice');
        $p = $this->project();
        $this->member($p, $alice);
        $this->subtask($this->task($p), $alice, 2);
        $this->container['projectModel']->disable($p);
        $this->actAs($alice);

        $r = $this->xp()->getUserXp($alice);
        $this->assertSame([$p => 10], $r['by_project']);
        $this->assertSame(10, $r['xp']);
    }

    public function testUserXpAdminSeesUnsharedProject(): void
    {
        $alice = $this->user('alice');
        $admin = (int) $this->container['userModel']->create(['username' => 'admin2', 'password' => 'x1234567', 'role' => \Kanboard\Core\Security\Role::APP_ADMIN]);
        $secret = $this->project('Secret');
        $this->subtask($this->task($secret), $alice, 2);
        $this->actAs($admin);

        $this->assertSame([$secret => 10], $this->xp()->getUserXp($alice)['by_project']);
    }

    public function testMilestoneProgressDeniedForNonMember(): void
    {
        $p = $this->project();
        $ms = $this->task($p);
        $this->milestone($ms, [$this->task($p)]);
        $this->actAs($this->user('outsider'));

        $this->expectException(AccessDeniedException::class);
        $this->progress()->getMilestoneProgress($ms);
    }

    public function testLeaderboardDeniedForNonMember(): void
    {
        $p = $this->project();
        $this->actAs($this->user('outsider'));

        $this->expectException(AccessDeniedException::class);
        $this->xp()->getXpLeaderboard($p);
    }

    public function testUserXpDeniedForHiddenProjectId(): void
    {
        $alice = $this->user('alice');
        $hidden = $this->project('Hidden');
        $this->actAs($this->user('outsider'));

        $this->expectException(AccessDeniedException::class);
        $this->xp()->getUserXp($alice, $hidden);
    }

    public function testMemberWithPersonalSessionGetsData(): void
    {
        $viewer = $this->user('viewer');
        $p = $this->project();
        $this->member($p, $viewer);
        $this->subtask($this->task($p), $viewer, 2);
        $this->actAs($viewer);

        $this->assertSame(1, $this->progress()->getProjectProgress($p)['total']);
        $this->assertSame([['user_id' => $viewer, 'xp' => 10, 'level' => 1, 'is_agent' => false, 'owner_id' => 0]], $this->xp()->getXpLeaderboard($p));
    }

    /** Spec sequencing #8: the API's numbers match the markup's for the same fixture. */
    public function testApiMatchesHelperMarkupValues(): void
    {
        $this->container['xpCache'] = fn ($c) => new \Kanboard\Plugin\TimeReport\Model\XpCache($c);
        $alice = $this->user('alice');
        $p = $this->project();
        $this->member($p, $alice);
        $t = $this->task($p, ['owner_id' => $alice, 'score' => 1]);
        $this->subtask($t, $alice, 2);
        $this->subtask($t, $alice, 0);
        $this->container['db']->table('tasks')->eq('id', $t)->update(['time_estimated' => 2, 'time_spent' => 3]);
        $this->actAs($alice);

        $helper = new \Kanboard\Plugin\TimeReport\Helper\ProgressHelper($this->container);
        $row = $this->container['taskFinderModel']->getExtendedQuery()->eq('tasks.id', $t)->findOne();
        $this->assertSame($helper->card($row), $this->progress()->getTaskProgress($t));

        $level = $helper->level();
        $api = $this->xp()->getUserXp($alice);
        $this->assertSame(10, $api['xp']);
        foreach (['xp', 'level', 'next'] as $k) {
            $this->assertSame($level[$k], $api[$k], $k);
        }
    }
}
