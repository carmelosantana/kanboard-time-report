<?php

namespace Kanboard\Plugin\TimeReport\Model;

use Kanboard\Core\Base;

/**
 * AiSummaryCache — the one read/write/classify path over per-row AI summaries.
 *
 * Task summaries live in task_has_metadata (one entry per task, shared across AI
 * profiles and users per the spec's D6). Aggregate (day/week) summaries live in a
 * single project_has_metadata JSON map keyed "<granularity>:<rowkey>", pruned to a
 * bounded size so the TEXT value cannot grow without limit.
 *
 * A cached entry is {hash, summary, highlights[], generated_at}. Freshness is a pure
 * comparison of the stored hash against the freshly-computed content hash, so the
 * controller and the CSV export classify identically.
 */
class AiSummaryCache extends Base
{
    public const TASK_KEY = 'timereport_ai_summary';
    public const AGG_KEY  = 'timereport_ai_agg';

    /** Cap on aggregate entries per project; oldest (by generated_at) are pruned first. */
    public const AGG_MAX_ENTRIES = 200;

    /** @return array{hash:string,summary:string,highlights:list<string>,generated_at:int}|null */
    public function getTask(int $taskId): ?array
    {
        $raw = (string) $this->taskMetadataModel->get($taskId, self::TASK_KEY, '');
        return $this->decode($raw);
    }

    public function saveTask(int $taskId, string $hash, string $summary, array $highlights): void
    {
        $this->taskMetadataModel->save($taskId, [
            self::TASK_KEY => $this->encode($hash, $summary, $highlights),
        ]);
    }

    /** @return array{hash:string,summary:string,highlights:list<string>,generated_at:int}|null */
    public function getAggregate(int $projectId, string $granularity, string $rowKey): ?array
    {
        $map = $this->aggregateMap($projectId);
        $k = self::aggMapKey($granularity, $rowKey);
        if (! isset($map[$k]) || ! is_array($map[$k])) {
            return null;
        }
        return $this->normalise($map[$k]);
    }

    public function saveAggregate(int $projectId, string $granularity, string $rowKey, string $hash, string $summary, array $highlights): void
    {
        $map = $this->aggregateMap($projectId);
        $map[self::aggMapKey($granularity, $rowKey)] = [
            'hash'         => $hash,
            'summary'      => $summary,
            'highlights'   => array_values(array_map('strval', $highlights)),
            'generated_at' => time(),
        ];
        $map = self::prune($map, self::AGG_MAX_ENTRIES);

        $this->projectMetadataModel->save($projectId, [
            self::AGG_KEY => json_encode($map, JSON_UNESCAPED_UNICODE),
        ]);
    }

    /** missing when absent, fresh when the stored hash matches, stale otherwise. */
    public static function classify(?array $cached, string $currentHash): string
    {
        if ($cached === null) {
            return 'missing';
        }
        return (string) ($cached['hash'] ?? '') === $currentHash ? 'fresh' : 'stale';
    }

    public static function aggMapKey(string $granularity, string $rowKey): string
    {
        return $granularity . ':' . $rowKey;
    }

    /** Drop the oldest entries by generated_at until at most $max remain. */
    public static function prune(array $map, int $max): array
    {
        if (count($map) <= $max) {
            return $map;
        }
        uasort($map, static fn ($a, $b) => (int) ($b['generated_at'] ?? 0) <=> (int) ($a['generated_at'] ?? 0));
        return array_slice($map, 0, $max, true);
    }

    /** @return array<string,array> */
    private function aggregateMap(int $projectId): array
    {
        $raw = (string) $this->projectMetadataModel->get($projectId, self::AGG_KEY, '');
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function encode(string $hash, string $summary, array $highlights): string
    {
        return json_encode([
            'hash'         => $hash,
            'summary'      => $summary,
            'highlights'   => array_values(array_map('strval', $highlights)),
            'generated_at' => time(),
        ], JSON_UNESCAPED_UNICODE);
    }

    /** @return array{hash:string,summary:string,highlights:list<string>,generated_at:int}|null */
    private function decode(string $raw): ?array
    {
        if ($raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $this->normalise($decoded) : null;
    }

    /** @return array{hash:string,summary:string,highlights:list<string>,generated_at:int} */
    private function normalise(array $entry): array
    {
        $highlights = [];
        foreach ($entry['highlights'] ?? [] as $h) {
            if (is_string($h)) {
                $highlights[] = $h;
            }
        }
        return [
            'hash'         => (string) ($entry['hash'] ?? ''),
            'summary'      => (string) ($entry['summary'] ?? ''),
            'highlights'   => $highlights,
            'generated_at' => (int) ($entry['generated_at'] ?? 0),
        ];
    }
}
