<?php

namespace Kanboard\Plugin\TimeReport\Model;

use Kanboard\Core\Base;
use Kanboard\Plugin\AiConnector\Model\ProviderRegistry;

/**
 * AiSummaryModel — builds a message payload from the completed-task detail set
 * and asks AiConnector's ProviderRegistry for a {summary, highlights} result.
 *
 * Boundary: only data already visible to the user (task title, hours, category,
 * tags, completion date) is sent — never descriptions or comments. AI proposes;
 * the user disposes. Degrades to nothing when the gate is closed (never called).
 */
class AiSummaryModel extends Base
{
    public const SCHEMA = [
        'name'   => 'time_report_summary',
        'schema' => [
            'type'       => 'object',
            'properties' => [
                'summary'    => ['type' => 'string'],
                'highlights' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => ['summary', 'highlights'],
        ],
    ];

    private const SYSTEM_PROMPT =
        'You summarize a consultant\'s completed work for a client-facing hours report. '
        . 'Given a list of completed tasks with hours, categories, tags and completion dates, '
        . 'write a concise professional narrative summary and a short list of highlights. '
        . 'Do not invent work that is not in the data.';

    private ?ProviderRegistry $injectedRegistry = null;

    public function setRegistry(ProviderRegistry $registry): void
    {
        $this->injectedRegistry = $registry;
    }

    /**
     * @param  array $detailTasks DetailRow[] (only allowed fields are forwarded)
     * @return array{summary:string, highlights:string[]}
     */
    public function summarize(array $detailTasks, ?string $profileId = null): array
    {
        $registry = $this->injectedRegistry ?? new ProviderRegistry($this->container);
        $messages = $this->buildMessages($detailTasks);
        $decoded  = $registry->structured($messages, json_encode(self::SCHEMA), $profileId);
        return $this->normalise($decoded);
    }

    /** Build the chat messages, forwarding ONLY the allowed fields. Public for the boundary test. */
    public function buildMessages(array $detailTasks): array
    {
        $safe = [];
        foreach ($detailTasks as $t) {
            $safe[] = [
                'title'          => (string) ($t['title'] ?? ''),
                'hours'          => round((float) ($t['hours'] ?? 0.0), 2),
                'category'       => (string) ($t['category'] ?? ''),
                'tags'           => array_values(array_map('strval', $t['tags'] ?? [])),
                'date_completed' => (string) ($t['date_completed'] ?? ''),
            ];
        }

        return [
            ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
            ['role' => 'user',   'content' => json_encode(['completed_tasks' => $safe], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)],
        ];
    }

    /** @return array{summary:string, highlights:string[]} */
    private function normalise(array $decoded): array
    {
        $summary = isset($decoded['summary']) && is_string($decoded['summary']) ? $decoded['summary'] : '';

        $highlights = [];
        if (isset($decoded['highlights']) && is_array($decoded['highlights'])) {
            foreach ($decoded['highlights'] as $h) {
                if (is_string($h) && trim($h) !== '') {
                    $highlights[] = $h;
                }
            }
        }

        return ['summary' => $summary, 'highlights' => $highlights];
    }
}
