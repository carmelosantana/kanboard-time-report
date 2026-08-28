<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\TimeReport\Model\AiSummaryCache;

/**
 * Task 4 — the thin cache over task + project metadata. One code path shared by the
 * rowSummary endpoint and the CSV export, with fresh/stale/missing classification.
 */
class AiSummaryCacheTest extends Base
{
    private function cache(): AiSummaryCache
    {
        return new AiSummaryCache($this->container);
    }

    public function testTaskRoundTrip(): void
    {
        $projectId = (int) $this->container['projectModel']->create(['name' => 'C'], 1, true);
        $taskId = (int) $this->container['taskCreationModel']->create(['title' => 'T', 'project_id' => $projectId]);

        $cache = $this->cache();
        $this->assertNull($cache->getTask($taskId), 'absent entry reads as null');

        $cache->saveTask($taskId, 'HASH1', 'A narrative', ['h1', 'h2']);
        $got = $cache->getTask($taskId);

        $this->assertNotNull($got);
        $this->assertSame('HASH1', $got['hash']);
        $this->assertSame('A narrative', $got['summary']);
        $this->assertSame(['h1', 'h2'], $got['highlights']);
        $this->assertGreaterThan(0, $got['generated_at']);
    }

    public function testClassify(): void
    {
        $this->assertSame('missing', AiSummaryCache::classify(null, 'H'));
        $this->assertSame('fresh', AiSummaryCache::classify(['hash' => 'H'], 'H'));
        $this->assertSame('stale', AiSummaryCache::classify(['hash' => 'OLD'], 'H'));
    }

    public function testTaskOverwrite(): void
    {
        $projectId = (int) $this->container['projectModel']->create(['name' => 'C2'], 1, true);
        $taskId = (int) $this->container['taskCreationModel']->create(['title' => 'T', 'project_id' => $projectId]);
        $cache = $this->cache();
        $cache->saveTask($taskId, 'H1', 'first', ['x']);
        $cache->saveTask($taskId, 'H2', 'second', ['y']);
        $got = $cache->getTask($taskId);
        $this->assertSame('H2', $got['hash']);
        $this->assertSame('second', $got['summary']);
    }

    public function testAggregateRoundTripPerKey(): void
    {
        $projectId = (int) $this->container['projectModel']->create(['name' => 'Agg'], 1, true);
        $cache = $this->cache();

        $this->assertNull($cache->getAggregate($projectId, 'day', '2026-03-10'));

        $cache->saveAggregate($projectId, 'day', '2026-03-10', 'DH', 'day sum', ['d']);
        $cache->saveAggregate($projectId, 'week', '2026-W11', 'WH', 'week sum', ['w']);

        $day = $cache->getAggregate($projectId, 'day', '2026-03-10');
        $week = $cache->getAggregate($projectId, 'week', '2026-W11');
        $this->assertSame('day sum', $day['summary']);
        $this->assertSame('DH', $day['hash']);
        $this->assertSame('week sum', $week['summary']);
        // Distinct granularity/key does not collide.
        $this->assertNull($cache->getAggregate($projectId, 'day', '2026-03-11'));
    }

    public function testAggregateMapIsPrunedToBound(): void
    {
        $projectId = (int) $this->container['projectModel']->create(['name' => 'Prune'], 1, true);
        $cache = $this->cache();
        for ($i = 0; $i < AiSummaryCache::AGG_MAX_ENTRIES + 25; $i++) {
            $cache->saveAggregate($projectId, 'day', '2026-01-' . str_pad((string) ($i % 28 + 1), 2, '0', STR_PAD_LEFT) . '-' . $i, 'H' . $i, 's' . $i, []);
        }
        $raw = $this->container['projectMetadataModel']->get($projectId, AiSummaryCache::AGG_KEY, '');
        $map = json_decode($raw, true);
        $this->assertLessThanOrEqual(AiSummaryCache::AGG_MAX_ENTRIES, count($map), 'aggregate map must stay bounded');
    }
}
