<?php

namespace Kanboard\Plugin\TimeReport\Helper;

use Kanboard\Core\Base;

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

    public function toMarkdown(array $report): string
    {
        $isTask = ($report['granularity'] ?? 'day') === 'task';
        $lines = [];
        $lines[] = '# Time Report — ' . $report['project_name'];
        $lines[] = '';
        $lines[] = '**Range:** ' . $report['start_date'] . ' → ' . $report['end_date'];
        $lines[] = '**Total hours:** ' . $this->formatHours((float) $report['total_hours']);
        $lines[] = '';

        if ($isTask) {
            $lines[] = '| Task | Hours |';
            $lines[] = '| --- | ---: |';
        } else {
            $lines[] = '| ' . $this->breakdownHeader($report['granularity']) . ' | Hours | Tasks |';
            $lines[] = '| --- | ---: | ---: |';
        }
        foreach ($report['breakdown'] as $row) {
            if ($isTask) {
                $lines[] = '| ' . $row['label'] . ' | ' . $this->formatHours((float) $row['hours']) . ' |';
            } else {
                $lines[] = '| ' . $row['label'] . ' | ' . $this->formatHours((float) $row['hours']) . ' | ' . (int) $row['task_count'] . ' |';
            }
        }

        if (! empty($report['include_detail']) && ! empty($report['detail'])) {
            $lines[] = '';
            $lines[] = '## Completed tasks';
            $lines[] = '';
            $lines[] = '| Ref | Title | Hours | Completed | Category | Tags |';
            $lines[] = '| --- | --- | ---: | --- | --- | --- |';
            foreach ($report['detail'] as $d) {
                $lines[] = '| ' . $d['reference'] . ' | ' . $d['title'] . ' | ' . $this->formatHours((float) $d['hours'])
                    . ' | ' . $d['date_completed'] . ' | ' . $d['category'] . ' | ' . implode('; ', $d['tags']) . ' |';
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
        $out[] = '';

        // Uniform breakdown header across all granularities (the Tasks count is 1
        // per row for task granularity — simple and documented).
        $out[] = $this->csvRow(['Label', 'Hours', 'Tasks']);
        foreach ($report['breakdown'] as $row) {
            $out[] = $this->csvRow([$row['label'], $this->formatHours((float) $row['hours']), (string) (int) $row['task_count']]);
        }

        if (! empty($report['include_detail']) && ! empty($report['detail'])) {
            $out[] = '';
            $out[] = $this->csvRow(['Reference', 'Title', 'Hours', 'Completed', 'Category', 'Tags']);
            foreach ($report['detail'] as $d) {
                $out[] = $this->csvRow([
                    $d['reference'], $d['title'], $this->formatHours((float) $d['hours']),
                    $d['date_completed'], $d['category'], implode('; ', $d['tags']),
                ]);
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

    private function breakdownHeader(string $granularity): string
    {
        return match ($granularity) {
            'week'  => 'Week',
            'task'  => 'Task',
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
