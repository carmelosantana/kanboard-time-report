<?php

namespace Kanboard\Plugin\TimeReport\Helper;

use Kanboard\Core\Base;
use Kanboard\Plugin\TimeReport\Model\TimeReportModel;

/**
 * TimeReportHelper — renders one report aggregate to hours / Markdown / CSV.
 * Pure formatting over the aggregate; no data access.
 */
class TimeReportHelper extends Base
{
    public function formatHours(float $hours): string
    {
        return number_format($hours, 2, '.', '');
    }

    /**
     * Prefix an ISO date (YYYY-MM-DD) with its abbreviated weekday: "Mon 2026-08-10".
     * Any string that is not a bare ISO date is returned unchanged, so it is safe to
     * call on any breakdown label (week ranges, task labels, "Total").
     */
    public function withWeekday(string $date): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return $date;
        }
        $ts = strtotime($date . ' 12:00:00');
        if ($ts === false) {
            return $date;
        }
        return date('D', $ts) . ' ' . $date;
    }

    /** An ISO date as the date fields show it: the format Kanboard's calendar writes. */
    public function fieldDate(string $isoDate): string
    {
        $ts = strtotime($isoDate . ' 12:00:00');

        return $ts === false ? $isoDate : date($this->dateParser->getUserDateFormat(), $ts);
    }

    /**
     * Ready-made ranges for the report forms, as ISO dates. Weeks start on the viewer's
     * first day of the week (Sunday unless they chose Monday), matching the per-week
     * breakdown.
     *
     * @return array<string, array{label:string,start:string,end:string}>
     */
    public function datePresets(?int $now = null): array
    {
        $now   = $now ?? time();
        $week  = TimeReportModel::weekStart($now, $this->weekStart());
        $first = strtotime(date('Y-m-01', $now));
        $range = static fn (string $label, int $start, int $end): array => ['label' => $label, 'start' => date('Y-m-d', $start), 'end' => date('Y-m-d', $end)];

        return [
            'this_week'  => $range(t('This week'), $week, strtotime('+6 days', $week)),
            'last_week'  => $range(t('Last week'), strtotime('-7 days', $week), strtotime('-1 day', $week)),
            'this_month' => $range(t('This month'), $first, strtotime('last day of this month', $first)),
            'last_month' => $range(t('Last month'), strtotime('first day of last month', $first), strtotime('last day of last month', $first)),
        ];
    }

    /** The viewer's first day of the week: 0 Sunday, 1 Monday. */
    public function weekStart(): int
    {
        return (new TimeReportModel($this->container))->weekStartFor((int) $this->userSession->getId());
    }

    public function toMarkdown(array $report): string
    {
        $isTask = ($report['granularity'] ?? 'day') === 'task';
        $lines = [];
        $lines[] = '# Time Report — ' . $report['project_name'];
        $lines[] = '';
        $lines[] = '**Range:** ' . $report['start_date'] . ' → ' . $report['end_date'];
        $lines[] = '**Total hours:** ' . $this->formatHours((float) $report['total_hours']);
        if (isset($report['tracked_hours'], $report['manual_hours'])) {
            $lines[] = '**Tracked hours:** ' . $this->formatHours((float) $report['tracked_hours']);
            $lines[] = '**Manual hours:** ' . $this->formatHours((float) $report['manual_hours']);
        }
        if (isset($report['xp']['total'])) {
            $lines[] = '**XP earned:** ' . (int) $report['xp']['total'];
        }
        $lines[] = '';

        $lines[] = '| ' . $this->breakdownHeader($report['granularity']) . ' | Tracked | Manual | Total |' . ($isTask ? '' : ' Tasks |');
        $lines[] = '| --- | ---: | ---: | ---: |' . ($isTask ? '' : ' ---: |');
        foreach ($report['breakdown'] as $row) {
            $lines[] = '| ' . $this->mdCell($this->withWeekday($row['label'])) . ' | ' . implode(' | ', $this->hourCells($row)) . ' |'
                . ($isTask ? '' : ' ' . (int) $row['task_count'] . ' |');
        }

        if (! empty($report['include_detail']) && ! empty($report['detail'])) {
            $multi = ! empty($report['multi_user']);
            $lines[] = '';
            $lines[] = '## Completed tasks';
            $lines[] = '';
            $lines[] = $multi
                ? '| Ref | Title | Assignee | Hours | Completed | Category | Tags |'
                : '| Ref | Title | Hours | Completed | Category | Tags |';
            $lines[] = $multi
                ? '| --- | --- | --- | ---: | --- | --- | --- |'
                : '| --- | --- | ---: | --- | --- | --- |';
            foreach ($report['detail'] as $d) {
                $assignee = $multi ? ($this->mdCell((string) ($d['assignee'] ?? '')) . ' | ') : '';
                $lines[] = '| ' . $this->mdCell($d['reference']) . ' | ' . $this->mdCell($d['title']) . ' | ' . $assignee . $this->formatHours((float) $d['hours'])
                    . ' | ' . $this->withWeekday($d['date_completed']) . ' | ' . $this->mdCell($d['category']) . ' | ' . $this->mdCell(implode('; ', $d['tags'])) . ' |';
            }
        }

        if (! empty($report['ai']) && is_array($report['ai']) && empty($report['ai']['error']) && (trim((string)($report['ai']['summary'] ?? '')) !== '' || !empty($report['ai']['highlights']))) {
            $lines[] = '';
            $lines[] = '## Summary';
            $lines[] = '';
            $lines[] = (string) ($report['ai']['summary'] ?? '');
            if (! empty($report['ai']['highlights'])) {
                $lines[] = '';
                foreach ($report['ai']['highlights'] as $h) {
                    $lines[] = '- ' . $h;
                }
            }
        }

        return implode("\n", $lines) . "\n";
    }

    public function toCsv(array $report): string
    {
        $out = [];
        $out[] = $this->csvRow(['# Time Report', $report['project_name']]);
        $out[] = $this->csvRow(['# Range', $report['start_date'], $report['end_date']]);
        $out[] = $this->csvRow(['# Total hours', $this->formatHours((float) $report['total_hours'])]);
        if (isset($report['tracked_hours'], $report['manual_hours'])) {
            $out[] = $this->csvRow(['# Tracked hours', $this->formatHours((float) $report['tracked_hours'])]);
            $out[] = $this->csvRow(['# Manual hours', $this->formatHours((float) $report['manual_hours'])]);
        }
        if (isset($report['xp']['total'])) {
            $out[] = $this->csvRow(['# XP earned', (string) (int) $report['xp']['total']]);
        }
        $out[] = '';

        // Uniform breakdown header across all granularities (the Tasks count is 1
        // per row for task granularity — simple and documented). The Summary column is
        // added only when the controller attached cache-only per-row summaries; uncached
        // or stale rows export blank (run "Generate all summaries" first for a full set).
        $withSummary = isset($report['row_summaries']) && is_array($report['row_summaries']);
        $summaries = $withSummary ? $report['row_summaries'] : [];

        $header = ['Label', 'Tracked', 'Manual', 'Hours', 'Tasks'];
        if ($withSummary) {
            $header[] = 'Summary';
        }
        $out[] = $this->csvRow($header);

        foreach ($report['breakdown'] as $row) {
            $fields = [$row['label'], ...$this->hourCells($row), (string) (int) $row['task_count']];
            if ($withSummary) {
                $fields[] = (string) ($summaries[(string) $row['key']] ?? '');
            }
            $out[] = $this->csvRow($fields);
        }

        if (! empty($report['include_detail']) && ! empty($report['detail'])) {
            $multi = ! empty($report['multi_user']);
            $out[] = '';
            $out[] = $multi
                ? $this->csvRow(['Reference', 'Title', 'Assignee', 'Hours', 'Completed', 'Category', 'Tags'])
                : $this->csvRow(['Reference', 'Title', 'Hours', 'Completed', 'Category', 'Tags']);
            foreach ($report['detail'] as $d) {
                $fields = [$d['reference'], $d['title']];
                if ($multi) {
                    $fields[] = (string) ($d['assignee'] ?? '');
                }
                $fields[] = $this->formatHours((float) $d['hours']);
                $fields[] = $d['date_completed'];
                $fields[] = $d['category'];
                $fields[] = implode('; ', $d['tags']);
                $out[] = $this->csvRow($fields);
            }
        }

        return implode("\r\n", $out) . "\r\n";
    }

    public function csvFilename(string $projectName, string $start, string $end): string
    {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $projectName));
        $slug = trim($slug, '-');
        if ($slug === '') {
            $slug = 'project';
        }
        return 'time-report-' . $slug . '-' . $start . '_' . $end . '.csv';
    }

    /**
     * A row's [tracked, manual, total] hours, formatted. A row without the split
     * (an older caller's aggregate) is all tracked.
     *
     * @return array{0:string,1:string,2:string}
     */
    private function hourCells(array $row): array
    {
        $total  = (float) $row['hours'];
        $manual = (float) ($row['manual_hours'] ?? 0.0);

        return [
            $this->formatHours((float) ($row['tracked_hours'] ?? ($total - $manual))),
            $this->formatHours($manual),
            $this->formatHours($total),
        ];
    }

    /** One Markdown table cell: a pipe or line break in the text would split the row. */
    private function mdCell(string $text): string
    {
        return str_replace('|', '\\|', preg_replace('/\s*[\r\n]+\s*/', ' ', $text));
    }

    private function breakdownHeader(string $granularity): string
    {
        return match ($granularity) {
            'week'  => 'Week',
            'task'  => 'Task',
            'user'  => 'User',
            'total' => 'Total',
            default => 'Day',
        };
    }

    private function csvRow(array $fields): string
    {
        return implode(',', array_map([$this, 'csvField'], $fields));
    }

    private function csvField(string $value): string
    {
        if (preg_match('/[",\r\n]/', $value)) {
            return '"' . str_replace('"', '""', $value) . '"';
        }
        return $value;
    }
}
