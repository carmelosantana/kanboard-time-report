<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\TimeReport\Helper\ProgressHelper;
use Kanboard\Plugin\TimeReport\Plugin;

class ProgressCardTest extends Base
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->container['helper']->register('timeReportProgress', ProgressHelper::class);
    }

    private function render(array $task): string
    {
        $task += ['id' => 42, 'project_id' => 1, 'nb_subtasks' => 0, 'nb_completed_subtasks' => 0, 'time_spent' => 0, 'time_estimated' => 0];
        return $this->container['template']->render('TimeReport:board/progress', ['task' => $task]);
    }

    public function testCardWithSubtasksAndEstimate(): void
    {
        $html = $this->render(['nb_subtasks' => 5, 'nb_completed_subtasks' => 3, 'time_spent' => 3.4, 'time_estimated' => 4]);
        $this->assertStringContainsString('class="tr-progress"', $html);
        $this->assertStringContainsString('data-tr-contract="1"', $html);
        $this->assertStringContainsString('data-tr-task="42"', $html);
        $this->assertStringContainsString('data-tr-subtasks-done="3"', $html);
        $this->assertStringContainsString('data-tr-subtasks-total="5"', $html);
        $this->assertStringContainsString('data-tr-pct="60"', $html);
        $this->assertStringContainsString('data-tr-time-spent="3.4"', $html);
        $this->assertStringContainsString('data-tr-time-est="4"', $html);
        $this->assertStringContainsString('data-tr-time-state="ok"', $html);
        $this->assertStringContainsString('role="progressbar"', $html);
        $this->assertStringContainsString('aria-valuenow="60"', $html);
        $this->assertStringContainsString('--tr-pct:60%', $html);
        $this->assertStringContainsString('3/5', $html);
    }

    public function testCardOverrunMarksOver(): void
    {
        $html = $this->render(['time_spent' => 6, 'time_estimated' => 4]);
        $this->assertStringContainsString('data-tr-time-state="over"', $html);
        $this->assertStringContainsString('tr-progress__time--over', $html);
        $this->assertStringNotContainsString('tr-progress__bar', $html);
    }

    public function testCardWithoutEstimateHasNoTimeMeter(): void
    {
        $html = $this->render(['nb_subtasks' => 2, 'nb_completed_subtasks' => 1, 'time_spent' => 3]);
        $this->assertStringContainsString('tr-progress__bar', $html);
        $this->assertStringNotContainsString('tr-progress__time', $html);
        $this->assertStringNotContainsString('data-tr-time-', $html);
    }

    public function testCardWithNothingRendersNothing(): void
    {
        $this->assertSame('', trim($this->render(['time_spent' => 2])));
    }

    public function testPluginAttachesFooterHookAndNoOverride(): void
    {
        $this->container->register(new \Kanboard\ServiceProvider\ApiProvider()); // initialize() registers JSON-RPC procedures
        (new Plugin($this->container))->initialize();
        $hooks = $this->container['hook']->getListeners('template:board:task:footer');
        $this->assertContains('TimeReport:board/progress', array_column($hooks, 'template'));
        $this->assertStringNotContainsString('setTemplateOverride', file_get_contents(dirname(__DIR__) . '/Plugin.php'));
    }

    public function testProgressCssShipsAndHidesTaskProgressBar(): void
    {
        $css = file_get_contents(dirname(__DIR__) . '/Assets/css/progress.css');
        $this->assertStringContainsString('.container-task-progress-bar', $css);
        $this->assertMatchesRegularExpression('/\.tr-track\s*,\s*\.tr-level\s*\{[^}]*display:\s*none/', $css);
        $this->assertStringContainsString('var(--tr-pct', $css);
    }
}
