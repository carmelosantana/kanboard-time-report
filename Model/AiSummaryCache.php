<?php

namespace Kanboard\Plugin\TimeReport\Model;

use Kanboard\Core\Base;

/**
 * AiSummaryCache — the one read/write/classify path over per-row AI summaries.
 *
 * Both caches live in the plugin's own tables (Schema/version_1). They used to
 * live in task_has_metadata / project_has_metadata, whose `value` column is
 * VARCHAR(255) on MySQL and Postgres — far too small for even a single entry,
 * which serializes to over 500 characters.
 *
 * Task summaries are one row per task (shared across AI profiles and users per
 * the spec's D6). Aggregate (day/week) summaries are one row per
 * project+granularity+rowkey, so a save touches only its own row: no
 * read-modify-write of a whole-project map, and no lost updates when two
 * generate requests run concurrently. Both cascade with their parent.
 *
 * A cached entry is {hash, summary, highlights[], generated_at}. Freshness is a
 * pure comparison of the stored hash against the freshly-computed content hash,
 * so the controller and the CSV export classify identically.
 */
class AiSummaryCache extends Base
{
    public const TASK_TABLE = 'timereport_task_summaries';
    public const AGG_TABLE  = 'timereport_aggregate_summaries';

    /** @return array{hash:string,summary:string,highlights:list<string>,generated_at:int}|null */
    public function getTask(int $taskId): ?array
    {
        $row = $this->db->table(self::TASK_TABLE)->eq('task_id', $taskId)->findOne();

        return is_array($row) ? $this->fromRow($row) : null;
    }

    public function saveTask(int $taskId, string $hash, string $summary, array $highlights): void
    {
        $this->upsert(
            $this->db->table(self::TASK_TABLE)->eq('task_id', $taskId),
            $this->db->table(self::TASK_TABLE),
            $this->toRow($hash, $summary, $highlights),
            ['task_id' => $taskId]
        );
    }

    /** @return array{hash:string,summary:string,highlights:list<string>,generated_at:int}|null */
    public function getAggregate(int $projectId, string $granularity, string $rowKey): ?array
    {
        $row = $this->db->table(self::AGG_TABLE)
            ->eq('project_id', $projectId)
            ->eq('granularity', $granularity)
            ->eq('row_key', $rowKey)
            ->findOne();

        return is_array($row) ? $this->fromRow($row) : null;
    }

    public function saveAggregate(int $projectId, string $granularity, string $rowKey, string $hash, string $summary, array $highlights): void
    {
        $identity = [
            'project_id'  => $projectId,
            'granularity' => $granularity,
            'row_key'     => $rowKey,
        ];

        $matching = $this->db->table(self::AGG_TABLE)
            ->eq('project_id', $projectId)
            ->eq('granularity', $granularity)
            ->eq('row_key', $rowKey);

        $this->upsert($matching, $this->db->table(self::AGG_TABLE), $this->toRow($hash, $summary, $highlights), $identity);
    }

    /** missing when absent, fresh when the stored hash matches, stale otherwise. */
    public static function classify(?array $cached, string $currentHash): string
    {
        if ($cached === null) {
            return 'missing';
        }
        return (string) ($cached['hash'] ?? '') === $currentHash ? 'fresh' : 'stale';
    }

    /**
     * Update the matching row or insert it with its identity columns.
     *
     * $matching and $insertTable are separate query builders because picodb
     * conditions are stateful — reusing one after a failed match would carry
     * its WHERE clause into the insert.
     */
    private function upsert(\PicoDb\Table $matching, \PicoDb\Table $insertTable, array $values, array $identity): void
    {
        if ($matching->exists()) {
            $matching->update($values);
            return;
        }

        $insertTable->insert($values + $identity);
    }

    /** @return array{hash:string,summary:string,highlights:string,generated_at:int} */
    private function toRow(string $hash, string $summary, array $highlights): array
    {
        return [
            'hash'         => $hash,
            'summary'      => $summary,
            'highlights'   => (string) json_encode(array_values(array_map('strval', $highlights)), JSON_UNESCAPED_UNICODE),
            'generated_at' => time(),
        ];
    }

    /** @return array{hash:string,summary:string,highlights:list<string>,generated_at:int} */
    private function fromRow(array $row): array
    {
        $decoded    = json_decode((string) ($row['highlights'] ?? ''), true);
        $highlights = [];

        foreach (is_array($decoded) ? $decoded : [] as $h) {
            if (is_string($h)) {
                $highlights[] = $h;
            }
        }

        return [
            'hash'         => (string) ($row['hash'] ?? ''),
            'summary'      => (string) ($row['summary'] ?? ''),
            'highlights'   => $highlights,
            'generated_at' => (int) ($row['generated_at'] ?? 0),
        ];
    }
}
