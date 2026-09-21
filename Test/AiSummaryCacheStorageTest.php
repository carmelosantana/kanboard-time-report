<?php

require_once 'tests/units/Base.php';
require_once __DIR__ . '/SummarySchemaHelper.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\TimeReport\Model\AiSummaryCache;

/**
 * The storage constraint the cache has to live inside.
 *
 * `task_has_metadata.value`, `project_has_metadata.value` and
 * `user_has_metadata.value` are VARCHAR(255) on MySQL (app/Schema/Sql/mysql.sql)
 * and Postgres (app/Schema/Postgres.php). SQLite ignores declared VARCHAR
 * lengths and the harness runs SQLite, so an oversized write is invisible here
 * while production raises SQLSTATE[22001] (MySQL 1406) or truncates silently
 * outside strict mode.
 *
 * These tests therefore assert on strlen() of the value actually written to
 * those columns rather than relying on the driver to reject it — the only
 * check that is driver-independent.
 */
class AiSummaryCacheStorageTest extends Base
{
    use SummarySchemaHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSummarySchema();
    }

    /** The VARCHAR length of every *_has_metadata.value column in core. */
    private const METADATA_VALUE_LIMIT = 255;

    /** A realistic AI narrative: a couple of sentences, as the model actually returns. */
    private function realisticSummary(): string
    {
        return 'Completed the authentication refactor across the login and session layers, '
            . 'migrated the legacy token store to the new encrypted backend, and verified '
            . 'the rollout against staging before handing off to QA.';
    }

    /** @return list<string> */
    private function realisticHighlights(): array
    {
        return [
            'Refactored session handling onto the encrypted token store',
            'Migrated 42 legacy records with a reversible backfill script',
            'Verified the staging rollout and handed the checklist to QA',
        ];
    }

    /** A content hash as TimeReportModel produces it: 64 hex characters. */
    private function realisticHash(string $seed): string
    {
        return hash('sha256', $seed);
    }

    /**
     * Every value the plugin has written into a metadata table, as "table.name" => value.
     *
     * @return array<string,string>
     */
    private function storedMetadataValues(): array
    {
        $pdo  = $this->container['db']->getConnection();
        $seen = [];

        foreach (['task_has_metadata', 'project_has_metadata', 'user_has_metadata'] as $table) {
            $rows = $pdo->query("SELECT name, value FROM {$table}")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $seen[$table . '.' . $row['name']] = (string) $row['value'];
            }
        }

        return $seen;
    }

    /**
     * No metadata value may exceed the column, and nothing may be parked under
     * the legacy cache keys at all. The second check keeps this from passing
     * vacuously on an install that happens to have no metadata rows.
     */
    private function assertMetadataValuesFitTheColumn(): void
    {
        $values = $this->storedMetadataValues();

        $this->assertArrayNotHasKey('task_has_metadata.timereport_ai_summary', $values);
        $this->assertArrayNotHasKey('project_has_metadata.timereport_ai_agg', $values);

        foreach ($values as $where => $value) {
            $this->assertLessThanOrEqual(
                self::METADATA_VALUE_LIMIT,
                strlen($value),
                sprintf(
                    '%s holds %d chars; the column is VARCHAR(%d) on MySQL and Postgres, '
                    . 'so this write fails with SQLSTATE[22001] in production.',
                    $where,
                    strlen($value),
                    self::METADATA_VALUE_LIMIT
                )
            );
        }
    }

    public function testSavingATaskSummaryWritesNothingOversizedIntoMetadata(): void
    {
        $projectId = (int) $this->container['projectModel']->create(['name' => 'Fits'], 1, true);
        $taskId    = (int) $this->container['taskCreationModel']->create(['title' => 'T', 'project_id' => $projectId]);

        (new AiSummaryCache($this->container))->saveTask(
            $taskId,
            $this->realisticHash('task'),
            $this->realisticSummary(),
            $this->realisticHighlights()
        );

        $this->assertNotNull((new AiSummaryCache($this->container))->getTask($taskId), 'the summary was stored');
        $this->assertMetadataValuesFitTheColumn();
    }

    public function testSavingASingleAggregateSummaryWritesNothingOversizedIntoMetadata(): void
    {
        $projectId = (int) $this->container['projectModel']->create(['name' => 'Agg'], 1, true);

        (new AiSummaryCache($this->container))->saveAggregate(
            $projectId,
            'day',
            '2026-03-10',
            $this->realisticHash('agg'),
            $this->realisticSummary(),
            $this->realisticHighlights()
        );

        $this->assertNotNull(
            (new AiSummaryCache($this->container))->getAggregate($projectId, 'day', '2026-03-10'),
            'the summary was stored'
        );
        $this->assertMetadataValuesFitTheColumn();
    }

    /**
     * A month of day rows is an ordinary report, not a pathological case. The
     * single-blob aggregate map grew past the column limit on the first entry
     * and kept growing from there.
     */
    public function testSavingManyAggregateSummariesWritesNothingOversizedIntoMetadata(): void
    {
        $projectId = (int) $this->container['projectModel']->create(['name' => 'Month'], 1, true);
        $cache     = new AiSummaryCache($this->container);

        for ($day = 1; $day <= 31; $day++) {
            $cache->saveAggregate(
                $projectId,
                'day',
                sprintf('2026-03-%02d', $day),
                $this->realisticHash('agg' . $day),
                $this->realisticSummary(),
                $this->realisticHighlights()
            );
        }

        $this->assertNotNull($cache->getAggregate($projectId, 'day', '2026-03-31'), 'every day was stored');
        $this->assertNotNull($cache->getAggregate($projectId, 'day', '2026-03-01'), 'no day was evicted');
        $this->assertMetadataValuesFitTheColumn();
    }

    /** The payload must survive storage intact, not merely fit somewhere. */
    public function testARealisticTaskSummaryRoundTripsIntact(): void
    {
        $projectId = (int) $this->container['projectModel']->create(['name' => 'RT'], 1, true);
        $taskId    = (int) $this->container['taskCreationModel']->create(['title' => 'T', 'project_id' => $projectId]);
        $hash      = $this->realisticHash('rt');

        $cache = new AiSummaryCache($this->container);
        $cache->saveTask($taskId, $hash, $this->realisticSummary(), $this->realisticHighlights());
        $got = $cache->getTask($taskId);

        $this->assertSame($hash, $got['hash']);
        $this->assertSame($this->realisticSummary(), $got['summary']);
        $this->assertSame($this->realisticHighlights(), $got['highlights']);
    }

    public function testARealisticAggregateSummaryRoundTripsIntact(): void
    {
        $projectId = (int) $this->container['projectModel']->create(['name' => 'RTA'], 1, true);
        $hash      = $this->realisticHash('rta');

        $cache = new AiSummaryCache($this->container);
        $cache->saveAggregate($projectId, 'week', '2026-W11', $hash, $this->realisticSummary(), $this->realisticHighlights());
        $got = $cache->getAggregate($projectId, 'week', '2026-W11');

        $this->assertSame($hash, $got['hash']);
        $this->assertSame($this->realisticSummary(), $got['summary']);
        $this->assertSame($this->realisticHighlights(), $got['highlights']);
    }
}
