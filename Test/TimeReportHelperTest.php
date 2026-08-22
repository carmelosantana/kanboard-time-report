<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\TimeReport\Helper\TimeReportHelper;

class TimeReportHelperTest extends Base
{
    private function helper(): TimeReportHelper
    {
        return new TimeReportHelper($this->container);
    }

    private function sampleReport(bool $detail = false): array
    {
        return [
            'project_id'     => 5,
            'project_name'   => 'Acme Website',
            'start_date'     => '2026-03-01',
            'end_date'       => '2026-03-31',
            'granularity'    => 'day',
            'total_hours'    => 6.5,
            'breakdown'      => [
                ['key' => '2026-03-10', 'label' => '2026-03-10', 'hours' => 3.5, 'task_count' => 2],
                ['key' => '2026-03-11', 'label' => '2026-03-11', 'hours' => 3.0, 'task_count' => 1],
            ],
            'include_detail' => $detail,
            'detail'         => $detail ? [
                ['task_id' => 7, 'reference' => 'ABC-7', 'title' => 'Build, "the" API', 'hours' => 3.5, 'date_completed' => '2026-03-10', 'category' => 'Dev', 'tags' => ['backend', 'urgent']],
            ] : [],
            'ai'             => null,
        ];
    }

    public function testFormatHoursTwoDecimals(): void
    {
        $this->assertSame('6.50', $this->helper()->formatHours(6.5));
        $this->assertSame('0.00', $this->helper()->formatHours(0.0));
        $this->assertSame('10.25', $this->helper()->formatHours(10.25));
    }

    public function testWithWeekdayPrefixesIsoDate(): void
    {
        $h = $this->helper();
        $this->assertSame('Tue 2026-03-10', $h->withWeekday('2026-03-10'));
        $this->assertSame('Wed 2026-03-11', $h->withWeekday('2026-03-11'));
        $this->assertMatchesRegularExpression('/^[A-Z][a-z]{2} \d{4}-\d{2}-\d{2}$/', $h->withWeekday('2026-08-10'));
    }

    public function testWithWeekdayLeavesNonIsoUnchanged(): void
    {
        $h = $this->helper();
        $this->assertSame('Aug 10 – Aug 16', $h->withWeekday('Aug 10 – Aug 16'));
        $this->assertSame('#63 Some title', $h->withWeekday('#63 Some title'));
        $this->assertSame('Total', $h->withWeekday('Total'));
        $this->assertSame('', $h->withWeekday(''));
    }

    public function testMarkdownHasHeaderTotalAndBreakdown(): void
    {
        $md = $this->helper()->toMarkdown($this->sampleReport());
        $this->assertStringContainsString('# Time Report — Acme Website', $md);
        $this->assertStringContainsString('2026-03-01', $md);
        $this->assertStringContainsString('2026-03-31', $md);
        $this->assertStringContainsString('**Total hours:** 6.50', $md);
        $this->assertStringContainsString('| 2026-03-10 | 3.50 |', $md);
    }

    public function testMarkdownIncludesDetailAndAiWhenPresent(): void
    {
        $report = $this->sampleReport(true);
        $report['ai'] = ['summary' => 'Solid week of backend work.', 'highlights' => ['Shipped API', 'Cleared backlog']];
        $md = $this->helper()->toMarkdown($report);
        $this->assertStringContainsString('ABC-7', $md);
        $this->assertStringContainsString('Build, "the" API', $md);
        $this->assertStringContainsString('backend; urgent', $md);
        $this->assertStringContainsString('Solid week of backend work.', $md);
        $this->assertStringContainsString('Shipped API', $md);
    }

    public function testCsvEscapesAndStructuresRows(): void
    {
        $csv = $this->helper()->toCsv($this->sampleReport(true));
        $this->assertStringContainsString('# Time Report,Acme Website', $csv);
        $this->assertStringContainsString('# Total hours,6.50', $csv);
        $this->assertStringContainsString("Label,Hours", $csv);
        $this->assertStringContainsString('2026-03-10,3.50', $csv);
        // Detail header + escaped title (embedded quotes doubled, field wrapped)
        $this->assertStringContainsString('Reference,Title,Hours,Completed,Category,Tags', $csv);
        $this->assertStringContainsString('"Build, ""the"" API"', $csv);
        $this->assertStringContainsString('backend; urgent', $csv);
    }

    public function testCsvTaskGranularityAddsTasksColumn(): void
    {
        $report = $this->sampleReport();
        $report['granularity'] = 'task';
        $report['breakdown'] = [['key' => '7', 'label' => '#7 Build API', 'hours' => 3.0, 'task_count' => 1]];
        $csv = $this->helper()->toCsv($report);
        $this->assertStringContainsString('Label,Hours,Tasks', $csv);
    }

    public function testCsvFilenameSlug(): void
    {
        $this->assertSame(
            'time-report-acme-website-2026-03-01_2026-03-31.csv',
            $this->helper()->csvFilename('Acme Website', '2026-03-01', '2026-03-31')
        );
    }

    public function testMarkdownOmitsSummaryOnAiError(): void
    {
        $report = $this->sampleReport();
        $report['ai'] = ['summary' => '', 'highlights' => [], 'error' => 'The AI summary could not be generated.'];
        $md = $this->helper()->toMarkdown($report);
        $this->assertStringNotContainsString('## Summary', $md);
    }
}
