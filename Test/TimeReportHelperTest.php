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
        $this->assertStringContainsString('| Tue 2026-03-10 | 3.50 |', $md);
    }

    public function testMarkdownPrefixesDatesWithWeekday(): void
    {
        $md = $this->helper()->toMarkdown($this->sampleReport(true));
        $this->assertStringContainsString('Tue 2026-03-10', $md); // breakdown label + detail date
        $this->assertStringContainsString('Wed 2026-03-11', $md); // breakdown label
    }

    public function testCsvKeepsBareIsoDatesNoWeekday(): void
    {
        $csv = $this->helper()->toCsv($this->sampleReport(true));
        $this->assertStringContainsString('2026-03-10', $csv);
        $this->assertStringNotContainsString('Tue', $csv);
        $this->assertStringNotContainsString('Wed', $csv);
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

    private function multiUserReport(): array
    {
        return [
            'project_name'   => 'Acme', 'start_date' => '2026-03-01', 'end_date' => '2026-03-31',
            'granularity'    => 'user', 'total_hours' => 9.0,
            'breakdown'      => [
                ['key' => 'u1', 'label' => 'Alice', 'hours' => 5.0, 'task_count' => 2],
                ['key' => 'u2', 'label' => 'Bob', 'hours' => 4.0, 'task_count' => 1],
            ],
            'include_detail' => true,
            'detail'         => [
                ['task_id' => 10, 'reference' => 'R1', 'title' => 'Ship it', 'assignee' => 'Bob',
                 'hours' => 4.0, 'date_completed' => '2026-03-12', 'category' => 'Dev', 'tags' => ['x']],
            ],
            'ai'             => null,
            'multi_user'     => true,
        ];
    }

    public function testMarkdownAddsAssigneeColumnWhenMultiUser(): void
    {
        $md = $this->helper()->toMarkdown($this->multiUserReport());

        $this->assertStringContainsString('| Ref | Title | Assignee | Hours | Completed | Category | Tags |', $md);
        $this->assertStringContainsString('| R1 | Ship it | Bob |', $md);
    }

    public function testMarkdownUsesUserHeaderForUserGranularity(): void
    {
        $md = $this->helper()->toMarkdown($this->multiUserReport());

        $this->assertStringContainsString('| User | Hours | Tasks |', $md);
        $this->assertStringContainsString('| Alice | 5.00 | 2 |', $md);
    }

    public function testCsvAddsAssigneeColumnWhenMultiUser(): void
    {
        $csv = $this->helper()->toCsv($this->multiUserReport());

        $this->assertStringContainsString('Reference,Title,Assignee,Hours,Completed,Category,Tags', $csv);
        $this->assertStringContainsString('R1,Ship it,Bob,4.00', $csv);
    }

    public function testSingleUserDetailOutputIsUnchanged(): void
    {
        $report = $this->multiUserReport();
        $report['multi_user']  = false;
        $report['granularity'] = 'task';

        $md  = $this->helper()->toMarkdown($report);
        $csv = $this->helper()->toCsv($report);

        $this->assertStringContainsString('| Ref | Title | Hours | Completed | Category | Tags |', $md);
        $this->assertStringNotContainsString('Assignee', $md);
        $this->assertStringContainsString('Reference,Title,Hours,Completed,Category,Tags', $csv);
        $this->assertStringNotContainsString('Assignee', $csv);
    }

    /**
     * Golden output. Substring checks would miss a changed separator, column order or
     * line ending, so the whole rendering is pinned for a single-user fixture.
     *
     * This asserts FORMAT, not arithmetic: the fixture's hours are given directly, so
     * the Task 2 attribution fix cannot move them. Never pin a fixture whose numbers
     * come from the contribution union — those legitimately changed.
     */
    public function testSingleUserMarkdownAndCsvAreByteIdentical(): void
    {
        $report = [
            'project_name' => 'Acme', 'start_date' => '2026-03-01', 'end_date' => '2026-03-31',
            'granularity'  => 'task', 'total_hours' => 3.0,
            'breakdown'    => [['key' => '10', 'label' => '#10 Ship it', 'hours' => 3.0, 'task_count' => 1]],
            'include_detail' => true,
            'detail' => [
                ['task_id' => 10, 'reference' => 'R1', 'title' => 'Ship it', 'assignee' => 'Alice',
                 'hours' => 3.0, 'date_completed' => '2026-03-12', 'category' => 'Dev', 'tags' => ['x']],
            ],
            'ai' => null, 'multi_user' => false,
        ];

        $expectedMd = "# Time Report — Acme\n"
            . "\n"
            . "**Range:** 2026-03-01 → 2026-03-31\n"
            . "**Total hours:** 3.00\n"
            . "\n"
            . "| Task | Hours |\n"
            . "| --- | ---: |\n"
            . "| #10 Ship it | 3.00 |\n"
            . "\n"
            . "## Completed tasks\n"
            . "\n"
            . "| Ref | Title | Hours | Completed | Category | Tags |\n"
            . "| --- | --- | ---: | --- | --- | --- |\n"
            . "| R1 | Ship it | 3.00 | Thu 2026-03-12 | Dev | x |\n";

        $expectedCsv = "# Time Report,Acme\r\n"
            . "# Range,2026-03-01,2026-03-31\r\n"
            . "# Total hours,3.00\r\n"
            . "\r\n"
            . "Label,Hours,Tasks\r\n"
            . "#10 Ship it,3.00,1\r\n"
            . "\r\n"
            . "Reference,Title,Hours,Completed,Category,Tags\r\n"
            . "R1,Ship it,3.00,2026-03-12,Dev,x\r\n";

        $this->assertSame($expectedMd, $this->helper()->toMarkdown($report));
        $this->assertSame($expectedCsv, $this->helper()->toCsv($report));
    }
}
