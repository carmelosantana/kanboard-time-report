<?php

/**
 * Shared seeding for the progress/XP tests. Runs the REAL schema migrations
 * (version_1 then version_2) so every test also exercises them.
 *
 * Not named *Test.php: PHPUnit collects that suffix only.
 */
trait ProgressFixture
{
    private function createProgressSchema(): void
    {
        require_once dirname(__DIR__) . '/Schema/Sqlite.php';

        $pdo = $this->container['db']->getConnection();
        $has = static function (string $table) use ($pdo): bool {
            $found = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='{$table}'")->fetchColumn();
            return $found !== false && $found !== null;
        };

        if (! $has('timereport_task_summaries')) {
            \Kanboard\Plugin\TimeReport\Schema\version_1($pdo);
        }
        if (! $has('timereport_xp_cache')) {
            \Kanboard\Plugin\TimeReport\Schema\version_2($pdo);
        }
    }

    private function user(string $name): int
    {
        return (int) $this->container['userModel']->create(['username' => $name, 'password' => 'x1234567', 'role' => 'app-user']);
    }

    private function project(string $name = 'P'): int
    {
        return (int) $this->container['projectModel']->create(['name' => $name], 1, true);
    }

    private function task(int $projectId, array $extra = []): int
    {
        return (int) $this->container['taskCreationModel']->create(['project_id' => $projectId] + $extra + ['title' => 'T']);
    }

    private function subtask(int $taskId, int $userId = 0, int $status = 0): int
    {
        return (int) $this->container['subtaskModel']->create(['task_id' => $taskId, 'title' => 'S', 'user_id' => $userId, 'status' => $status]);
    }

    private function close(int $taskId): void
    {
        $this->container['taskStatusModel']->close($taskId);
    }

    /** Link $milestoneId as "is a milestone of" each member (core creates the opposite link). */
    private function milestone(int $milestoneId, array $memberIds): void
    {
        $link = $this->container['linkModel']->getByLabel('is a milestone of');
        foreach ($memberIds as $memberId) {
            $this->container['taskLinkModel']->create($milestoneId, $memberId, (int) $link['id']);
        }
    }

    /** Stand-in for the Agents plugin's table (that plugin is not loaded in this harness). */
    private function agentsTable(array $agentToOwner): void
    {
        $this->container['db']->getConnection()->exec(
            'CREATE TABLE IF NOT EXISTS agents (id INTEGER PRIMARY KEY, owner_user_id INTEGER NOT NULL, agent_user_id INTEGER NOT NULL UNIQUE, kind TEXT NOT NULL, created_at INTEGER NOT NULL DEFAULT 0)'
        );
        foreach ($agentToOwner as $agentId => $ownerId) {
            $this->container['db']->table('agents')->insert([
                'owner_user_id' => $ownerId, 'agent_user_id' => $agentId, 'kind' => 'claude', 'created_at' => 0,
            ]);
        }
    }
}
