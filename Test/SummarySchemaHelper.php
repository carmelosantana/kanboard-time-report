<?php

/**
 * Creates the plugin's tables in the test database by running the REAL schema
 * migration, so every test that touches the cache also exercises the migration
 * rather than a hand-rolled copy that could drift from it.
 *
 * Not named *Test.php on purpose — PHPUnit collects that suffix only, so this
 * file is never mistaken for a test case.
 *
 * Only the SQLite variant is loaded: all three Schema files declare the same
 * namespace and function names (that is how Kanboard's SchemaHandler works —
 * it requires exactly one, chosen by driver), so requiring a second would be a
 * fatal duplicate-function error. The harness runs on SQLite.
 */
trait SummarySchemaHelper
{
    private function createSummarySchema(): void
    {
        require_once dirname(__DIR__) . '/Schema/Sqlite.php';

        $pdo = $this->container['db']->getConnection();
        $existing = $pdo
            ->query("SELECT name FROM sqlite_master WHERE type='table' AND name='timereport_task_summaries'")
            ->fetchColumn();

        if ($existing === false || $existing === null) {
            \Kanboard\Plugin\TimeReport\Schema\version_1($pdo);
        }
    }
}
