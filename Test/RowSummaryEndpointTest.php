<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\TimeReport\Model\TimeReportModel;
use Kanboard\Plugin\TimeReport\Model\AiSummaryModel;
use Kanboard\Plugin\TimeReport\Model\AiSummaryCache;
use Kanboard\Plugin\TimeReport\Controller\TimeReportController;

/**
 * Task 7 — the rowSummary endpoint state machine (fresh/stale/missing/force).
 *
 * Real TimeReportModel + real AiSummaryCache; only the AI model is stubbed (counting
 * calls, no network) so we can assert exactly when the model spends.
 */
class RowSummaryEndpointTest extends Base
{
    private int $taskCalls = 0;
    private int $aggCalls = 0;
    public ?string $lastTaskProfile = 'unset';
    public ?string $lastAggProfile = 'unset';

    protected function setUp(): void
    {
        parent::setUp();
        // The endpoint reads the current user from the session; log in as user 1 (admin),
        // the owner of every project seeded below, so assertProjectAccess passes.
        $_SESSION['user'] = ['id' => 1, 'role' => 'app-admin'];
    }

    private function wireServices(): void
    {
        $this->container['timeReportModel'] = fn ($c) => new TimeReportModel($c);
        $this->container['aiSummaryCache']  = fn ($c) => new AiSummaryCache($c);

        $test = $this;
        $this->container['aiSummaryModel'] = function ($c) use ($test) {
            return new class($c, $test) extends AiSummaryModel {
                public function __construct($c, private $test) { parent::__construct($c); }
                public function summarizeTask(array $row, ?string $profileId = null): array {
                    $this->test->bumpTask($profileId);
                    return ['summary' => 'TASK:' . ($row['title'] ?? ''), 'highlights' => ['h']];
                }
                public function summarizeAggregate(string $g, string $label, array $members, ?string $profileId = null): array {
                    $this->test->bumpAgg($profileId);
                    return ['summary' => 'AGG:' . $label . ':' . count($members), 'highlights' => []];
                }
            };
        };
    }

    public function bumpTask(?string $profileId = null): void { $this->taskCalls++; $this->lastTaskProfile = $profileId; }
    public function bumpAgg(?string $profileId = null): void { $this->aggCalls++; $this->lastAggProfile = $profileId; }

    private function controller(): object
    {
        return new class($this->container) extends TimeReportController {
            protected function isAiEnabled(): bool { return true; }
            public function run(array $values): array { return $this->computeRowSummary($values); }
        };
    }

    /** A closed task with a tracked subtask this month → one 'task' breakdown row. */
    private function seedTaskWithSubtask(string $title = 'Parent'): array
    {
        $projectId = (int) $this->container['projectModel']->create(['name' => 'RS ' . uniqid()], 1, true);
        $taskId = (int) $this->container['taskCreationModel']->create(['title' => $title, 'project_id' => $projectId, 'owner_id' => 1]);
        $subId = (int) $this->container['subtaskModel']->create(['task_id' => $taskId, 'title' => 'Do work', 'user_id' => 1]);
        $this->container['db']->table('subtasks')->eq('id', $subId)->update(['time_spent' => 2.0]);
        $this->container['db']->table('subtask_time_tracking')->insert([
            'subtask_id' => $subId, 'user_id' => 1,
            'start' => strtotime(date('Y-m-05') . ' 09:00:00'),
            'end'   => strtotime(date('Y-m-05') . ' 11:00:00'),
            'time_spent' => 2.0,
        ]);
        $this->container['taskStatusModel']->close($taskId);
        return [$projectId, $taskId, $subId];
    }

    /** Add another closed task with a tracked subtask (same day) to an existing project. */
    private function addTaskWithSubtask(int $projectId, string $title): int
    {
        $taskId = (int) $this->container['taskCreationModel']->create(['title' => $title, 'project_id' => $projectId, 'owner_id' => 1]);
        $subId = (int) $this->container['subtaskModel']->create(['task_id' => $taskId, 'title' => 'Work', 'user_id' => 1]);
        $this->container['db']->table('subtasks')->eq('id', $subId)->update(['time_spent' => 1.0]);
        $this->container['db']->table('subtask_time_tracking')->insert([
            'subtask_id' => $subId, 'user_id' => 1,
            'start' => strtotime(date('Y-m-05') . ' 09:00:00'),
            'end'   => strtotime(date('Y-m-05') . ' 10:00:00'),
            'time_spent' => 1.0,
        ]);
        $this->container['taskStatusModel']->close($taskId);
        return $taskId;
    }

    private function values(int $projectId, string $granularity, string $rowKey, bool $force = false): array
    {
        return [
            'project_id'  => (string) $projectId,
            'granularity' => $granularity,
            'row_key'     => $rowKey,
            'start_date'  => date('Y-m-01'),
            'end_date'    => date('Y-m-d'),
            'force'       => $force ? '1' : '',
        ];
    }

    public function testTaskMissingGeneratesAndCaches(): void
    {
        $this->wireServices();
        [$projectId, $taskId] = $this->seedTaskWithSubtask('Alpha');

        $out = $this->controller()->run($this->values($projectId, 'task', (string) $taskId));

        $this->assertSame('TASK:Alpha', $out['summary']);
        $this->assertFalse($out['stale']);
        $this->assertSame(1, $this->taskCalls, 'missing row must generate exactly once');

        // Cache now holds it.
        $cached = (new AiSummaryCache($this->container))->getTask($taskId);
        $this->assertSame('TASK:Alpha', $cached['summary']);
    }

    public function testTaskFreshServesCacheWithoutSpending(): void
    {
        $this->wireServices();
        [$projectId, $taskId] = $this->seedTaskWithSubtask('Beta');
        $c = $this->controller();

        $c->run($this->values($projectId, 'task', (string) $taskId));   // generate
        $this->assertSame(1, $this->taskCalls);

        $out = $c->run($this->values($projectId, 'task', (string) $taskId)); // fresh
        $this->assertSame(1, $this->taskCalls, 'fresh row must NOT regenerate');
        $this->assertFalse($out['stale']);
        $this->assertSame('TASK:Beta', $out['summary']);
    }

    public function testTaskStaleServesCacheFlaggedWithoutSpending(): void
    {
        $this->wireServices();
        [$projectId, $taskId, $subId] = $this->seedTaskWithSubtask('Gamma');
        $c = $this->controller();

        $c->run($this->values($projectId, 'task', (string) $taskId));   // generate
        $this->assertSame(1, $this->taskCalls);

        // Edit the subtask content → content hash changes → cached entry is now stale.
        $this->container['db']->table('subtasks')->eq('id', $subId)->update(['title' => 'Do MORE work']);

        $out = $c->run($this->values($projectId, 'task', (string) $taskId));
        $this->assertTrue($out['stale'], 'edited content must read as stale');
        $this->assertSame(1, $this->taskCalls, 'stale must serve cache without spending');
        $this->assertSame('TASK:Gamma', $out['summary'], 'stale still returns the last cached summary');
    }

    public function testForceRegeneratesEvenWhenFresh(): void
    {
        $this->wireServices();
        [$projectId, $taskId] = $this->seedTaskWithSubtask('Delta');
        $c = $this->controller();

        $c->run($this->values($projectId, 'task', (string) $taskId));   // generate
        $this->assertSame(1, $this->taskCalls);

        $out = $c->run($this->values($projectId, 'task', (string) $taskId, true)); // force
        $this->assertSame(2, $this->taskCalls, 'force must regenerate');
        $this->assertFalse($out['stale']);
    }

    public function testUnknownRowReturnsError(): void
    {
        $this->wireServices();
        [$projectId] = $this->seedTaskWithSubtask('Epsilon');
        $out = $this->controller()->run($this->values($projectId, 'task', '99999999'));
        $this->assertArrayHasKey('error', $out);
    }

    public function testInvalidGranularityReturnsError(): void
    {
        $this->wireServices();
        [$projectId, $taskId] = $this->seedTaskWithSubtask('Zeta');
        $out = $this->controller()->run($this->values($projectId, 'user', (string) $taskId));
        $this->assertArrayHasKey('error', $out);
    }

    public function testDayAggregateGeneratesMembersThenComposes(): void
    {
        $this->wireServices();
        [$projectId, $taskId] = $this->seedTaskWithSubtask('Eta');
        $dayKey = date('Y-m-05');
        $c = $this->controller();

        $out = $c->run($this->values($projectId, 'day', $dayKey));
        $this->assertSame(1, $this->taskCalls, 'the day row generates its one member task summary');
        $this->assertSame(1, $this->aggCalls, 'then composes the aggregate');
        $this->assertStringStartsWith('AGG:', $out['summary']);
        $this->assertFalse($out['stale']);

        // Second call: everything cached → no new spend.
        $out2 = $c->run($this->values($projectId, 'day', $dayKey));
        $this->assertSame(1, $this->taskCalls, 'member summary reused from cache');
        $this->assertSame(1, $this->aggCalls, 'aggregate reused from cache');
        $this->assertFalse($out2['stale']);
    }

    public function testMissingAggregateWithStaleMemberIsFlaggedAndDoesNotSpend(): void
    {
        $this->wireServices();
        [$projectId, $taskId, $subId] = $this->seedTaskWithSubtask('Kappa');
        $dayKey = date('Y-m-05');
        $c = $this->controller();

        // Generate ONLY the member task summary (task row) — the day aggregate is never
        // composed, so its cache entry stays missing.
        $c->run($this->values($projectId, 'task', (string) $taskId));
        $this->assertSame(1, $this->taskCalls);
        $this->assertSame(0, $this->aggCalls, 'the aggregate was never generated');

        // Change the member's content → its cached summary is now stale.
        $this->container['db']->table('subtasks')->eq('id', $subId)->update(['title' => 'Do OTHER work']);

        // Compose the day aggregate: the member is served stale (no spend, D7). A missing
        // aggregate composed from a stale member must be badged, not returned as fresh —
        // and must not spend to compose an outdated narrative.
        $out = $c->run($this->values($projectId, 'day', $dayKey));
        $this->assertTrue($out['stale'], 'a missing aggregate over a stale member must be flagged stale');
        $this->assertSame(1, $this->taskCalls, 'stale member is not regenerated (no spend)');
        $this->assertSame(0, $this->aggCalls, 'a stale-member aggregate must not spend to compose');

        // It must not have been persisted as fresh: forcing a regenerate rebuilds cleanly.
        $out2 = $c->run($this->values($projectId, 'day', $dayKey, true));
        $this->assertFalse($out2['stale'], 'force regenerates the member and re-composes the aggregate');
        $this->assertSame(2, $this->taskCalls, 'force regenerates the member');
        $this->assertSame(1, $this->aggCalls, 'force composes the aggregate once');
    }

    public function testSelectedProfileIsValidatedAndThreadedToGeneration(): void
    {
        $this->wireServices();
        // One real AiConnector profile so validProfileId accepts it.
        $this->container['configModel']->save(['aiconnector_profiles' => json_encode([
            ['id' => 'p-luna', 'label' => 'Luna', 'provider' => 'openai', 'model' => 'gpt-5-luna'],
        ])]);
        [$projectId, $taskId] = $this->seedTaskWithSubtask('Lambda');
        $c = $this->controller();

        // A valid selected profile is forwarded to the summarize call.
        $values = $this->values($projectId, 'task', (string) $taskId);
        $values['profile_id'] = 'p-luna';
        $c->run($values);
        $this->assertSame('p-luna', $this->lastTaskProfile, 'a valid profile id is forwarded to generation');

        // An unknown profile is rejected by validation → default (null).
        $values2 = $this->values($projectId, 'task', (string) $taskId, true); // force → regenerate
        $values2['profile_id'] = 'ghost-profile';
        $c->run($values2);
        $this->assertNull($this->lastTaskProfile, 'an unknown profile id is validated away to the default');
    }

    public function testSelectedProfileThreadsThroughToAggregateComposition(): void
    {
        $this->wireServices();
        $this->container['configModel']->save(['aiconnector_profiles' => json_encode([
            ['id' => 'p-luna', 'label' => 'Luna', 'provider' => 'openai', 'model' => 'gpt-5-luna'],
        ])]);
        [$projectId, $taskId] = $this->seedTaskWithSubtask('Mu');
        $dayKey = date('Y-m-05');
        $c = $this->controller();

        $values = $this->values($projectId, 'day', $dayKey);
        $values['profile_id'] = 'p-luna';
        $c->run($values);

        $this->assertSame('p-luna', $this->lastTaskProfile, 'member generation honors the profile');
        $this->assertSame('p-luna', $this->lastAggProfile, 'aggregate composition honors the profile');
    }

    // ── Task 11: cache-only summaries feed the CSV export ──────────────────────

    private function csvController(): object
    {
        return new class($this->container) extends TimeReportController {
            protected function isAiEnabled(): bool { return true; }
            public function run(array $values): array { return $this->computeRowSummary($values); }
            public function cachedFor(array $report): array { return $this->cachedRowSummaries($report); }
            public function report(array $values): array {
                $userId = $this->userSession->getId();
                return $this->timeReportModel->report(
                    (int) $values['project_id'], $values['start_date'], $values['end_date'],
                    $values['granularity'], true, $userId
                );
            }
        };
    }

    public function testCsvSummariesMineTaskContentOnceAcrossRows(): void
    {
        $this->wireServices();

        // Count buildTaskContentRows invocations without changing its behavior.
        $counter = new \stdClass();
        $counter->n = 0;
        $this->container['timeReportModel'] = function ($c) use ($counter) {
            return new class($c, $counter) extends TimeReportModel {
                public $counter;
                public function __construct($c, $counter) { parent::__construct($c); $this->counter = $counter; }
                public function buildTaskContentRows(int $projectId, array $taskIds, bool $includeDescriptions): array {
                    $this->counter->n++;
                    return parent::buildTaskContentRows($projectId, $taskIds, $includeDescriptions);
                }
            };
        };

        [$projectId, $t1] = $this->seedTaskWithSubtask('One');
        $this->addTaskWithSubtask($projectId, 'Two');
        $this->addTaskWithSubtask($projectId, 'Three');

        $c = $this->csvController();
        $report = $c->report($this->values($projectId, 'task', (string) $t1));
        $this->assertCount(3, $report['breakdown'], 'three task rows in the report');

        // Attaching cache-only summaries must mine task content ONCE for the whole report,
        // not re-mine per row (the N+1 the report already computed).
        $counter->n = 0;
        $c->cachedFor($report);
        $this->assertSame(1, $counter->n, 'CSV summaries mine task content once, not per row');
    }

    public function testCsvSummariesIncludeFreshOmitStaleAndMissing(): void
    {
        $this->wireServices();
        [$projectId, $taskId, $subId] = $this->seedTaskWithSubtask('Iota');
        $c = $this->csvController();

        // Nothing cached yet → the row is omitted (blank in CSV).
        $report = $c->report($this->values($projectId, 'task', (string) $taskId));
        $this->assertSame([], $c->cachedFor($report), 'uncached rows are omitted');

        // Generate → now fresh and included.
        $c->run($this->values($projectId, 'task', (string) $taskId));
        $report = $c->report($this->values($projectId, 'task', (string) $taskId));
        $summaries = $c->cachedFor($report);
        $this->assertSame('TASK:Iota', $summaries[(string) $taskId] ?? null, 'fresh cached row is included');

        // Edit content → stale → omitted again (no generation on export).
        $this->container['db']->table('subtasks')->eq('id', $subId)->update(['time_spent' => 9.0]);
        $report = $c->report($this->values($projectId, 'task', (string) $taskId));
        $this->assertArrayNotHasKey((string) $taskId, $c->cachedFor($report), 'stale rows export blank');
    }

    public function testDayAggregateStaleWhenMemberChanges(): void
    {
        $this->wireServices();
        [$projectId, $taskId, $subId] = $this->seedTaskWithSubtask('Theta');
        $dayKey = date('Y-m-05');
        $c = $this->controller();

        $c->run($this->values($projectId, 'day', $dayKey)); // generate member + agg
        $this->assertSame(1, $this->aggCalls);

        // Change a member's content → aggregate hash no longer matches.
        $this->container['db']->table('subtasks')->eq('id', $subId)->update(['time_spent' => 5.0]);

        $out = $c->run($this->values($projectId, 'day', $dayKey));
        $this->assertTrue($out['stale'], 'a changed member makes the aggregate stale');
        $this->assertSame(1, $this->aggCalls, 'stale aggregate must not spend');
    }
}
