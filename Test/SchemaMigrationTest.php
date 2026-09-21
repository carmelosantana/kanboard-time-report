<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\TimeReport\Model\AiSummaryCache;

/**
 * version_1 moves the AI summary caches out of the VARCHAR(255) metadata
 * columns and into the plugin's own tables.
 *
 * Unlike TimeInvoice's equivalent migration there is deliberately NO data
 * migration: every cached entry is regenerable from the source report and is
 * hash-validated by AiSummaryCache::classify(), so a cold cache costs one
 * regeneration rather than a migration that has to cope with rows MySQL
 * already truncated mid-JSON.
 *
 * This runs the real migration function — the same one Kanboard's
 * SchemaHandler calls when the plugin is enabled or updated.
 */
class SchemaMigrationTest extends Base
{
    /**
     * The metadata keys 1.4.1 wrote to. Spelled out here rather than read from
     * AiSummaryCache, which no longer knows about them — same reason the
     * migration itself spells them out.
     */
    private const LEGACY_TASK_KEY = 'timereport_ai_summary';
    private const LEGACY_AGG_KEY  = 'timereport_ai_agg';

    private function migrate(): void
    {
        require_once dirname(__DIR__) . '/Schema/Sqlite.php';
        \Kanboard\Plugin\TimeReport\Schema\version_1($this->container['db']->getConnection());
    }

    private function tableExists(string $table): bool
    {
        $found = $this->container['db']->getConnection()
            ->query("SELECT name FROM sqlite_master WHERE type='table' AND name='{$table}'")
            ->fetchColumn();

        return $found !== false && $found !== null;
    }

    public function testMigrationCreatesTheSummaryTables(): void
    {
        $this->migrate();

        $this->assertTrue($this->tableExists('timereport_task_summaries'));
        $this->assertTrue($this->tableExists('timereport_aggregate_summaries'));
    }

    public function testMigrationIsSafeOnAnInstallWithNoData(): void
    {
        $this->migrate();

        $cache = new AiSummaryCache($this->container);
        $this->assertNull($cache->getTask(1));
        $this->assertNull($cache->getAggregate(1, 'day', '2026-03-10'));
    }

    /**
     * The legacy rows are a cache, nothing else reads them, and on MySQL many
     * of them are truncated garbage. The migration clears them so they stop
     * occupying space.
     */
    public function testMigrationRemovesTheLegacyMetadataCacheRows(): void
    {
        $projectId = (int) $this->container['projectModel']->create(['name' => 'Legacy'], 1, true);
        $taskId    = (int) $this->container['taskCreationModel']->create(['title' => 'T', 'project_id' => $projectId]);

        $this->container['taskMetadataModel']->save($taskId, [
            self::LEGACY_TASK_KEY => '{"hash":"H","summary":"old","highlights":[],"generated_at":1}',
        ]);
        $this->container['projectMetadataModel']->save($projectId, [
            self::LEGACY_AGG_KEY => '{"day:2026-03-10":{"hash":"H","summary":"old","highlights":[],"generated_at":1}}',
        ]);

        $this->migrate();

        $this->assertSame('', (string) $this->container['taskMetadataModel']->get($taskId, self::LEGACY_TASK_KEY, ''));
        $this->assertSame('', (string) $this->container['projectMetadataModel']->get($projectId, self::LEGACY_AGG_KEY, ''));
    }

    /** Unrelated metadata belonging to other plugins must be left alone. */
    public function testMigrationLeavesOtherMetadataUntouched(): void
    {
        $projectId = (int) $this->container['projectModel']->create(['name' => 'Other'], 1, true);
        $taskId    = (int) $this->container['taskCreationModel']->create(['title' => 'T', 'project_id' => $projectId]);

        $this->container['taskMetadataModel']->save($taskId, ['someplugin_key' => 'keep me']);
        $this->container['projectMetadataModel']->save($projectId, ['timeinvoice:defaults' => '{"rate":150}']);

        $this->migrate();

        $this->assertSame('keep me', (string) $this->container['taskMetadataModel']->get($taskId, 'someplugin_key', ''));
        $this->assertSame('{"rate":150}', (string) $this->container['projectMetadataModel']->get($projectId, 'timeinvoice:defaults', ''));
    }

    /** A deleted task or project must not leave orphaned cache rows behind. */
    public function testCachedSummariesCascadeWhenTheirParentIsDeleted(): void
    {
        $this->migrate();

        $projectId = (int) $this->container['projectModel']->create(['name' => 'Doomed'], 1, true);
        $taskId    = (int) $this->container['taskCreationModel']->create(['title' => 'T', 'project_id' => $projectId]);

        $cache = new AiSummaryCache($this->container);
        $cache->saveTask($taskId, 'H', 'task summary', ['h']);
        $cache->saveAggregate($projectId, 'day', '2026-03-10', 'H', 'day summary', ['h']);

        $this->container['db']->getConnection()->exec('PRAGMA foreign_keys = ON');
        $this->container['projectModel']->remove($projectId);

        $this->assertNull($cache->getTask($taskId), 'task summaries are removed with their task');
        $this->assertNull($cache->getAggregate($projectId, 'day', '2026-03-10'), 'aggregate summaries are removed with their project');
    }
}
