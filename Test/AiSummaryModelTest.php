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

    /** Boundary: the message payload must carry only titles/hours/category/tags/dates — no descriptions. */
    public function testBuildMessagesOnlyIncludesAllowedFields(): void
    {
        $detail = [[
            'task_id' => 7, 'reference' => 'ABC-7', 'title' => 'Build API', 'hours' => 3.5,
            'date_completed' => '2026-03-10', 'category' => 'Dev', 'tags' => ['backend'],
            'description' => 'SECRET internal notes', // must NOT leak even if present
        ]];
        $m = new AiSummaryModel($this->container);
        $messages = $m->buildMessages($detail);
        $blob = json_encode($messages);
        $this->assertStringContainsString('Build API', $blob);
        $this->assertStringContainsString('backend', $blob);
        $this->assertStringNotContainsString('SECRET internal notes', $blob);
        $this->assertStringNotContainsString('description', $blob);
    }
}
