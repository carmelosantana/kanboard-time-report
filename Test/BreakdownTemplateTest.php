<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\TimeReport\Helper\TimeReportHelper;

/** The breakdown table as rendered HTML. */
class BreakdownTemplateTest extends Base
{
    private function render(array $report): string
    {
        $this->container['helper']->register('timeReport', TimeReportHelper::class);

        return $this->container['template']->render('TimeReport:report/_breakdown', ['report' => $report, 'ai_enabled' => false]);
    }

    private function report(string $granularity, array $breakdown): array
    {
        return [
            'project_id' => 5, 'start_date' => '2026-03-01', 'end_date' => '2026-03-31',
            'granularity' => $granularity, 'subject_user_ids' => [1],
            'total_hours' => array_sum(array_column($breakdown, 'hours')),
            'tracked_hours' => array_sum(array_column($breakdown, 'tracked_hours')),
            'manual_hours' => array_sum(array_column($breakdown, 'manual_hours')),
            'breakdown' => $breakdown,
        ];
    }

    private function cells(string $html, string $rowTag = 'tbody'): array
    {
        preg_match('#<' . $rowTag . '>(.*?)</' . $rowTag . '>#s', $html, $m);
        preg_match_all('#<t[dh][^>]*>(.*?)</t[dh]>#s', $m[1] ?? '', $cells);

        return array_map(static fn ($c) => trim(strip_tags($c)), $cells[1]);
    }

    public function testDayRowShowsTrackedManualAndTotal(): void
    {
        $html = $this->render($this->report('day', [
            ['key' => '2026-03-10', 'label' => '2026-03-10', 'hours' => 2.5, 'tracked_hours' => 2.0, 'manual_hours' => 0.5, 'task_count' => 2, 'task_ids' => [1, 2]],
        ]));

        $this->assertSame(['Period', 'Tracked', 'Manual', 'Total', 'Tasks'], $this->cells($html, 'thead'));
        $this->assertSame(['Tue 2026-03-10', '2.00', '0.50', '2.50', '2'], $this->cells($html));
    }

    public function testFooterTotalsEveryColumn(): void
    {
        $html = $this->render($this->report('day', [
            ['key' => '2026-03-10', 'label' => '2026-03-10', 'hours' => 2.5, 'tracked_hours' => 2.0, 'manual_hours' => 0.5, 'task_count' => 2, 'task_ids' => [1, 2]],
            ['key' => '2026-03-11', 'label' => '2026-03-11', 'hours' => 1.0, 'tracked_hours' => 0.0, 'manual_hours' => 1.0, 'task_count' => 1, 'task_ids' => [2]],
        ]));

        $this->assertSame(['Total', '2.00', '1.50', '3.50', ''], $this->cells($html, 'tfoot'));
    }

    public function testTaskRowLinksToTheTask(): void
    {
        $html = $this->render($this->report('task', [
            ['key' => '42', 'label' => '#42 Ship it', 'hours' => 1.0, 'tracked_hours' => 1.0, 'manual_hours' => 0.0, 'task_count' => 1, 'task_ids' => [42]],
        ]));

        $this->assertMatchesRegularExpression('#<a href="[^"]*task_id=42[^"]*"[^>]*>\#42 Ship it</a>#', $html);
    }

    public function testEmptyRangeSaysSoInsteadOfShowingAnEmptyTable(): void
    {
        $html = $this->render($this->report('day', []));

        $this->assertStringContainsString('No hours were logged in this range.', $html);
        $this->assertStringNotContainsString('<table', $html);
    }
}
