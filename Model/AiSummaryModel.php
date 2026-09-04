<?php

namespace Kanboard\Plugin\TimeReport\Model;

use Kanboard\Core\Base;
use Kanboard\Plugin\AiConnector\Model\ProviderRegistry;

/**
 * AiSummaryModel — turns completed-work detail into a {summary, highlights} result
 * via AiConnector's ProviderRegistry.
 *
 * Payload boundary: task title, hours, category, tags, completion date and the task's
 * completed subtasks (title + hours) are always forwarded. The task description is
 * forwarded ONLY when the admin opt-in is on — the gather layer omits it otherwise, so
 * an empty description never reaches this class. Comments are NEVER sent. AI proposes;
 * the user disposes. Degrades to nothing when the gate is closed (never called).
 */
class AiSummaryModel extends Base
{
    // additionalProperties:false + every property in `required` is mandatory for
    // OpenAI Chat Completions strict Structured Outputs (the "ChatGPT" profile).
    // Without it OpenAI rejects the request with an HTTP 400 and the report shows
    // "The summary could not be generated." Lenient providers (Anthropic, Ollama)
    // do not require it, so the omission only ever failed on OpenAI.
    public const SCHEMA = [
        'name'   => 'time_report_summary',
        'schema' => [
            'type'                 => 'object',
            'properties'           => [
                'summary'    => ['type' => 'string'],
                'highlights' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required'             => ['summary', 'highlights'],
            'additionalProperties' => false,
        ],
    ];

    private const SYSTEM_PROMPT =
        'You summarize a consultant\'s completed work for a client-facing hours report. '
        . 'Given completed tasks with hours, categories, tags and completion dates, '
        . 'write a concise professional narrative summary and a short list of highlights. '
        . 'Each task includes its completed subtasks and, when available, a description; '
        . 'ground the narrative in those. Do not invent work that is not in the data.';

    private const TASK_SYSTEM_PROMPT =
        'You summarize a single completed task for a client-facing hours report. '
        . 'The task includes its completed subtasks and, when available, a description; '
        . 'ground a concise professional narrative and a short list of highlights in those. '
        . 'Do not invent work that is not in the data.';

    private const AGGREGATE_SYSTEM_PROMPT =
        'You compose a concise client-facing summary for a %s of work from the per-task '
        . 'summaries provided. Synthesize a short professional narrative and a few highlights '
        . 'across the tasks. Use only what the per-task summaries state; do not introduce work '
        . 'not present in them.';

    private ?ProviderRegistry $injectedRegistry = null;

    public function setRegistry(ProviderRegistry $registry): void
    {
        $this->injectedRegistry = $registry;
    }

    /**
     * Report-level summary over the whole detail set (used for the user/total breakdowns).
     *
     * @param  array $detailTasks DetailRow[]
     * @return array{summary:string, highlights:string[]}
     */
    public function summarize(array $detailTasks, ?string $profileId = null): array
    {
        $registry = $this->injectedRegistry ?? new ProviderRegistry($this->container);
        $messages = $this->buildMessages($detailTasks);
        $decoded  = $registry->structured($messages, json_encode(self::SCHEMA), $profileId);
        return $this->normalise($decoded);
    }

    /**
     * Per-task summary for one DetailRow (task breakdown, and the members of day/week).
     *
     * @param  array $row DetailRow
     * @return array{summary:string, highlights:string[]}
     */
    public function summarizeTask(array $row, ?string $profileId = null): array
    {
        $registry = $this->injectedRegistry ?? new ProviderRegistry($this->container);
        $messages = $this->buildTaskMessages($row);
        $decoded  = $registry->structured($messages, json_encode(self::SCHEMA), $profileId);
        return $this->normalise($decoded);
    }

    /**
     * Aggregate (day/week) summary composed from the member tasks' cached summaries.
     * A summary-of-summaries — no raw task data is re-sent (D5).
     *
     * @param  array $memberTaskSummaries list<array{title?:string,summary:string,highlights?:string[]}>
     * @return array{summary:string, highlights:string[]}
     */
    public function summarizeAggregate(string $granularity, string $rowLabel, array $memberTaskSummaries, ?string $profileId = null): array
    {
        $registry = $this->injectedRegistry ?? new ProviderRegistry($this->container);
        $messages = $this->buildAggregateMessages($granularity, $rowLabel, $memberTaskSummaries);
        $decoded  = $registry->structured($messages, json_encode(self::SCHEMA), $profileId);
        return $this->normalise($decoded);
    }

    /** Build the report-level chat messages. Public for the boundary test. */
    public function buildMessages(array $detailTasks): array
    {
        $safe = [];
        foreach ($detailTasks as $t) {
            $safe[] = $this->safeTask($t);
        }

        return [
            ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
            ['role' => 'user',   'content' => json_encode(['completed_tasks' => $safe], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)],
        ];
    }

    /** Build the single-task chat messages. Public for the boundary test. */
    public function buildTaskMessages(array $row): array
    {
        return [
            ['role' => 'system', 'content' => self::TASK_SYSTEM_PROMPT],
            ['role' => 'user',   'content' => json_encode(['task' => $this->safeTask($row)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)],
        ];
    }

    /** Build the aggregate chat messages from member summaries. Public for the boundary test. */
    public function buildAggregateMessages(string $granularity, string $rowLabel, array $memberTaskSummaries): array
    {
        $members = [];
        foreach ($memberTaskSummaries as $m) {
            $summary = trim((string) ($m['summary'] ?? ''));
            if ($summary === '' && empty($m['highlights'])) {
                continue;
            }
            $entry = ['summary' => $summary];
            if (isset($m['title']) && $m['title'] !== '') {
                $entry['title'] = (string) $m['title'];
            }
            $highlights = [];
            foreach ($m['highlights'] ?? [] as $h) {
                if (! is_string($h)) {
                    continue;
                }
                $clean = self::cleanHighlight($h);
                if ($clean !== '') {
                    $highlights[] = $clean;
                }
            }
            if ($highlights !== []) {
                $entry['highlights'] = $highlights;
            }
            $members[] = $entry;
        }

        $system = sprintf(self::AGGREGATE_SYSTEM_PROMPT, $granularity === 'week' ? 'week' : 'day');

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => json_encode(['period' => $rowLabel, 'task_summaries' => $members], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)],
        ];
    }

    /**
     * The forwardable projection of one DetailRow. Only allow-listed fields leave the
     * box; subtasks carry title + hours; description appears only when present (the
     * gather layer already stripped it when the opt-in is off). Comments never appear.
     */
    private function safeTask(array $t): array
    {
        $safe = [
            'title'          => (string) ($t['title'] ?? ''),
            'hours'          => round((float) ($t['hours'] ?? 0.0), 2),
            'category'       => (string) ($t['category'] ?? ''),
            'tags'           => array_values(array_map('strval', $t['tags'] ?? [])),
            'date_completed' => (string) ($t['date_completed'] ?? ''),
        ];

        $subtasks = [];
        foreach ($t['subtasks'] ?? [] as $s) {
            $subtasks[] = [
                'title' => (string) ($s['title'] ?? ''),
                'hours' => round((float) ($s['hours'] ?? 0.0), 2),
                'done'  => (int) ($s['status'] ?? 0) === 2,
            ];
        }
        if ($subtasks !== []) {
            $safe['subtasks'] = $subtasks;
        }

        $description = trim((string) ($t['description'] ?? ''));
        if ($description !== '') {
            $safe['description'] = $description;
        }

        return $safe;
    }

    /** @return array{summary:string, highlights:string[]} */
    private function normalise(array $decoded): array
    {
        $summary = isset($decoded['summary']) && is_string($decoded['summary']) ? $decoded['summary'] : '';

        $highlights = [];
        if (isset($decoded['highlights']) && is_array($decoded['highlights'])) {
            foreach ($decoded['highlights'] as $h) {
                if (! is_string($h)) {
                    continue;
                }
                $clean = self::cleanHighlight($h);
                if ($clean !== '') {
                    $highlights[] = $clean;
                }
            }
        }

        return ['summary' => $summary, 'highlights' => $highlights];
    }

    /**
     * Strip a single leading markdown bullet marker (-, *, •, –, —) plus its trailing
     * space from a highlight, so a model that returns "- Shipped the API" renders as a
     * clean list item instead of a double bullet. A space after the marker is required,
     * so a meaningful leading "-5%" is left intact.
     */
    private static function cleanHighlight(string $h): string
    {
        $h = trim($h);
        $h = preg_replace('/^[-*\x{2022}\x{2013}\x{2014}]+(\s+|$)/u', '', $h);
        return trim((string) $h);
    }
}
