<?php

require_once 'tests/units/Base.php';
require_once __DIR__ . '/RoutingStubRegistry.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\TimeReport\Plugin;
use Kanboard\Plugin\TimeReport\Helper\TimeReportHelper;
use Kanboard\Plugin\TimeReport\Model\AiSummaryModel;
use Kanboard\Plugin\TimeReport\Model\TimeReportModel;
use Kanboard\Plugin\TimeReport\Controller\TimeReportController;

/** AiConnector 1.4.0 model routing: features registered, the profile resolved server-side per project. */
class ModelRoutingTest extends Base
{
    private function features(): array
    {
        $this->container->register(new \Kanboard\ServiceProvider\ApiProvider());
        (new Plugin($this->container))->initialize();
        $out = [];
        foreach ($this->container['hook']->getListeners('aiconnector:features') as $listener) {
            foreach ($listener() as $e) {
                $out[$e['id']] = $e;
            }
        }
        return $out;
    }

    public function testBothFeaturesAreRegistered(): void
    {
        $features = $this->features();
        $this->assertSame(['timereport.summary', 'timereport.row_summary'], array_keys($features));
        foreach ($features as $e) {
            $this->assertSame('TimeReport', $e['plugin']);
            $this->assertNotSame('', $e['label']);
        }
    }

    public function testTheAiConnectorFloorIs140(): void
    {
        $j = json_decode(file_get_contents(dirname(__DIR__) . '/plugin.json'), true);
        $deps = array_column($j['recommends'], 'min_version', 'plugin');
        $this->assertSame('1.4.0', $deps['AiConnector']);
    }

    public function testTheGateNeedsTheRoutingApi(): void
    {
        $this->assertStringContainsString("method_exists(", file_get_contents(dirname(__DIR__) . '/Model/AiGate.php'));
        $this->assertStringContainsString("'resolve'", file_get_contents(dirname(__DIR__) . '/Model/AiGate.php'));
    }

    // ── report-level summary ────────────────────────────────────────────────

    private function summaryController(RoutingStubRegistry $registry, array $post): object
    {
        $this->container['timeReportModel'] = fn ($c) => new class($c) extends TimeReportModel {
            public function report(int $projectId, string $startDate, string $endDate, string $granularity, bool $includeDetail, int $userId, ?array $subjectUserIds = null, bool $allUsers = false): array
            {
                return ['project_id' => $projectId, 'detail' => [], 'ai' => null];
            }
        };
        $this->container['aiSummaryModel'] = fn ($c) => new class($c) extends AiSummaryModel {
            public array $profiles = [];
            public function summarize(array $detailTasks, ?string $profileId = null): array
            {
                $this->profiles[] = $profileId;
                return ['summary' => 'canned', 'highlights' => []];
            }
        };
        $this->container['request'] = new \Kanboard\Core\Http\Request($this->container, ['REQUEST_METHOD' => 'POST'], [], $post + [
            'csrf_token' => $this->container['token']->getCSRFToken(),
            'start_date' => '2026-03-01', 'end_date' => '2026-03-31', 'granularity' => 'day', 'include_ai_summary' => '1',
        ]);

        return new class($this->container, $registry) extends TimeReportController {
            public function __construct($c, private $stub) { parent::__construct($c); }
            protected function isAiEnabled(): bool { return true; }
            protected function registry(): \Kanboard\Plugin\AiConnector\Model\ProviderRegistry { return $this->stub; }
            public function build(): array { return $this->buildReportFromRequest(); }
        };
    }

    public function testASingleProjectReportResolvesWithThatProject(): void
    {
        $registry = new RoutingStubRegistry($this->container);
        $report = $this->summaryController($registry, ['project_id' => '5', 'ai_profile' => 'deep'])->build();

        $this->assertSame([['timereport.summary', 5, 'deep']], $registry->resolves);
        $this->assertSame('canned', $report['ai']['summary']);
        $this->assertSame(['routed'], $this->container['aiSummaryModel']->profiles, 'the resolved id, never the posted one, reaches the model');
        $this->assertSame('deep', $report['ai_profile'], 'the pick rides on the report so the row forms forward it');
    }

    public function testAMultiProjectReportResolvesWithNoProject(): void
    {
        $registry = new RoutingStubRegistry($this->container);
        $this->summaryController($registry, ['project_id' => '0'])->build();

        $this->assertSame([['timereport.summary', null, null]], $registry->resolves);
    }

    public function testNothingReadyMeansNoSummaryAndNoCall(): void
    {
        $registry = new RoutingStubRegistry($this->container);
        $registry->returns = '';
        $report = $this->summaryController($registry, ['project_id' => '5'])->build();

        $this->assertSame([], $this->container['aiSummaryModel']->profiles);
        $this->assertSame('', $report['ai']['summary']);
        $this->assertNotEmpty($report['ai']['error']);
    }

    // ── hidden fields carry ai_profile ──────────────────────────────────────

    public function testTheRowFormsCarryAiProfile(): void
    {
        $this->container['helper']->register('timeReport', TimeReportHelper::class);
        $base = [
            'project_id' => 5, 'start_date' => '2026-03-01', 'end_date' => '2026-03-31', 'granularity' => 'task',
            'subject_user_ids' => [1], 'ai_profile' => 'deep', 'include_detail' => true, 'ai' => ['summary' => 's', 'highlights' => []],
            'total_hours' => 1.0, 'tracked_hours' => 1.0, 'manual_hours' => 0.0, 'participants' => [1 => ['name' => 'Me', 'hours' => 1.0]],
            'breakdown' => [['key' => '1', 'label' => 'T', 'hours' => 1.0, 'tracked_hours' => 1.0, 'manual_hours' => 0.0, 'task_count' => 1, 'task_ids' => [1]]],
            'untracked' => ['task_count' => 1, 'subtask_count' => 1, 'total_hours' => 2.0, 'tasks' => [['task_id' => 42, 'reference' => 'R1', 'title' => 'U', 'hours' => 2.0]]],
        ];
        foreach (['_breakdown', '_users', '_untracked'] as $partial) {
            $html = $this->container['template']->render('TimeReport:report/' . $partial, ['report' => $base, 'ai_enabled' => true]);
            $this->assertStringContainsString('name="ai_profile" value="deep"', $html, $partial);
            $this->assertStringNotContainsString('profile_id', $html, $partial);
        }
    }

    public function testNoTemplateStillPostsProfileId(): void
    {
        foreach (glob(dirname(__DIR__) . '/Template/report/*.php') as $file) {
            $this->assertStringNotContainsString('profile_id', file_get_contents($file), basename($file));
        }
    }
}
