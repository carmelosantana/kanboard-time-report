<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\AiConnector\Model\ProviderRegistry;
use Kanboard\Plugin\TimeReport\Model\AiSummaryModel;

class AiSummaryModelTest extends Base
{
    private function fakeRegistry(mixed $return, ?\Throwable $throw = null): ProviderRegistry
    {
        return new class($this->container, $return, $throw) extends ProviderRegistry {
            public function __construct($c, private mixed $r, private ?\Throwable $t) { parent::__construct($c); }
            public function structured(array $messages, string $schema, ?string $profileId = null): array {
                if ($this->t !== null) { throw $this->t; }
                return is_array($this->r) ? $this->r : [];
            }
        };
    }

    private function model(mixed $return): AiSummaryModel
    {
        $m = new AiSummaryModel($this->container);
        $m->setRegistry($this->fakeRegistry($return));
        return $m;
    }

    private function detail(): array
    {
        return [
            ['task_id' => 7, 'reference' => 'ABC-7', 'title' => 'Build API', 'hours' => 3.5, 'date_completed' => '2026-03-10', 'category' => 'Dev', 'tags' => ['backend']],
        ];
    }

    public function testSummarizeReturnsNormalizedResult(): void
    {
        $m = $this->model(['summary' => 'Good week.', 'highlights' => ['Shipped API', 'Cleared backlog']]);
        $out = $m->summarize($this->detail());
        $this->assertSame('Good week.', $out['summary']);
        $this->assertSame(['Shipped API', 'Cleared backlog'], $out['highlights']);
    }

    public function testSummarizeGracefulOnMalformed(): void
    {
        $m = $this->model(['unexpected' => true]);
        $out = $m->summarize($this->detail());
        $this->assertSame('', $out['summary']);
        $this->assertSame([], $out['highlights']);
    }

    public function testSummarizeDropsNonStringHighlights(): void
    {
        $m = $this->model(['summary' => 'x', 'highlights' => ['ok', 42, null, 'fine']]);
        $out = $m->summarize($this->detail());
        $this->assertSame(['ok', 'fine'], $out['highlights']);
    }

    /**
     * Boundary: the payload carries titles/hours/category/tags/dates + completed
     * subtasks, and the description ONLY when the gather layer supplied one (opt-in on).
     * Comments are never present.
     */
    public function testBuildMessagesIncludesSubtasksAndGatedDescription(): void
    {
        $withDesc = [[
            'task_id' => 7, 'reference' => 'ABC-7', 'title' => 'Build API', 'hours' => 3.5,
            'date_completed' => '2026-03-10', 'category' => 'Dev', 'tags' => ['backend'],
            'description' => 'Design notes here',
            'subtasks' => [['title' => 'Write endpoint', 'status' => 2, 'hours' => 2.0]],
            'comments' => ['should never be sent'],
        ]];
        $m = new AiSummaryModel($this->container);
        $blob = json_encode($m->buildMessages($withDesc));
        $this->assertStringContainsString('Build API', $blob);
        $this->assertStringContainsString('backend', $blob);
        $this->assertStringContainsString('Write endpoint', $blob, 'completed subtasks must be forwarded');
        $this->assertStringContainsString('Design notes here', $blob, 'description forwarded when present');
        $this->assertStringNotContainsString('should never be sent', $blob, 'comments must never be sent');
        $this->assertStringNotContainsString('comment', $blob);
    }

    public function testBuildMessagesOmitsEmptyDescription(): void
    {
        // The gather layer sets description to '' when the opt-in is off; it must not appear.
        $noDesc = [[
            'task_id' => 7, 'title' => 'Build API', 'hours' => 3.5, 'category' => 'Dev',
            'tags' => [], 'date_completed' => '2026-03-10', 'description' => '',
            'subtasks' => [],
        ]];
        $m = new AiSummaryModel($this->container);
        $messages = $m->buildMessages($noDesc);
        // Inspect the USER payload only (the system prompt legitimately mentions descriptions).
        $userBlob = $messages[1]['content'];
        $this->assertStringNotContainsString('description', $userBlob, 'empty description must not be forwarded');
    }

    public function testBuildTaskMessagesForSingleRow(): void
    {
        $row = [
            'task_id' => 9, 'title' => 'Refactor auth', 'hours' => 4.0, 'category' => 'Dev',
            'tags' => ['security'], 'date_completed' => '2026-03-12',
            'description' => 'Move to sessions',
            'subtasks' => [['title' => 'Swap tokens', 'status' => 2, 'hours' => 1.5]],
        ];
        $m = new AiSummaryModel($this->container);
        $blob = json_encode($m->buildTaskMessages($row));
        $this->assertStringContainsString('Refactor auth', $blob);
        $this->assertStringContainsString('Swap tokens', $blob);
        $this->assertStringContainsString('Move to sessions', $blob);
        $this->assertStringNotContainsString('comment', $blob);
    }

    public function testSummarizeTaskReturnsNormalized(): void
    {
        $m = new AiSummaryModel($this->container);
        $m->setRegistry($this->fakeRegistry(['summary' => 'Task done.', 'highlights' => ['Shipped']]));
        $out = $m->summarizeTask(['title' => 'X', 'hours' => 1.0, 'subtasks' => []]);
        $this->assertSame('Task done.', $out['summary']);
        $this->assertSame(['Shipped'], $out['highlights']);
    }
}
