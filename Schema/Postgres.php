<?php

namespace Kanboard\Plugin\TimeReport\Schema;

use PDO;

const VERSION = 2;

/**
 * Move the AI summary caches out of the metadata tables.
 *
 * `task_has_metadata.value` and `project_has_metadata.value` are VARCHAR(255)
 * on MySQL and Postgres, but a single cached entry — a 64-char content hash, a
 * two-sentence narrative and a few highlights — serializes to well over 500
 * characters, and the aggregate map held one blob per project that grew with
 * every row reported. Every write failed with SQLSTATE[22001] (MySQL 1406) or
 * truncated silently outside strict mode. SQLite ignores declared VARCHAR
 * lengths, which is why this was invisible in the test harness.
 *
 * Aggregates become one row per entry rather than one JSON map per project.
 * That removes the read-modify-write of the whole map on every save, the
 * lost-update window between two concurrent generate requests, and the
 * AGG_MAX_ENTRIES pruning that existed only to bound the blob.
 *
 * No data is migrated: every entry is regenerable from the source report and
 * is hash-validated by AiSummaryCache::classify(), so a cold cache costs one
 * regeneration. The legacy rows are deleted instead — nothing else reads them,
 * and on MySQL most of them are truncated garbage.
 */
function version_1(PDO $pdo)
{
    $pdo->exec("
        CREATE TABLE timereport_task_summaries (
            task_id INT NOT NULL,
            hash VARCHAR(64) NOT NULL DEFAULT '',
            summary TEXT NOT NULL,
            highlights TEXT NOT NULL,
            generated_at INT NOT NULL DEFAULT 0,
            PRIMARY KEY(task_id),
            FOREIGN KEY(task_id) REFERENCES tasks(id) ON DELETE CASCADE
        )
    ");

    $pdo->exec("
        CREATE TABLE timereport_aggregate_summaries (
            project_id INT NOT NULL,
            granularity VARCHAR(20) NOT NULL,
            row_key VARCHAR(64) NOT NULL,
            hash VARCHAR(64) NOT NULL DEFAULT '',
            summary TEXT NOT NULL,
            highlights TEXT NOT NULL,
            generated_at INT NOT NULL DEFAULT 0,
            PRIMARY KEY(project_id, granularity, row_key),
            FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
        )
    ");

    drop_legacy_metadata_cache($pdo);
}

/**
 * Delete the superseded cache rows.
 *
 * The key names are written out rather than read from AiSummaryCache so this
 * migration keeps meaning the same thing if those constants are ever renamed.
 * Scoped to exactly those two names: other plugins' metadata is untouched.
 */
function drop_legacy_metadata_cache(PDO $pdo)
{
    $pdo->exec("DELETE FROM task_has_metadata WHERE name = 'timereport_ai_summary'");
    $pdo->exec("DELETE FROM project_has_metadata WHERE name = 'timereport_ai_agg'");
}

/**
 * Progress + XP (1.5.0, spec Kanboard #5379/#5383).
 *
 * Core subtasks carry no completion time, so date-range XP needs a stamp per
 * done subtask. The stamp only DATES XP; amounts are always derived from the
 * live subtask/task rows. The XP cache holds lifetime XP per user per project;
 * project_id 0 is the per-user marker row (xp = lifetime total, party_xp = own +
 * agents' total, NULL when the user has no agents). It is fully disposable.
 */
function version_2(PDO $pdo)
{
    $pdo->exec("
        CREATE TABLE timereport_subtask_completions (
            subtask_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL DEFAULT 0,
            completed_at INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY(subtask_id),
            FOREIGN KEY(subtask_id) REFERENCES subtasks(id) ON DELETE CASCADE
        )
    ");
    $pdo->exec('CREATE INDEX timereport_subtask_completions_at_idx ON timereport_subtask_completions(completed_at)');

    $pdo->exec("
        CREATE TABLE timereport_xp_cache (
            user_id INTEGER NOT NULL,
            project_id INTEGER NOT NULL,
            xp INTEGER NOT NULL DEFAULT 0,
            party_xp INTEGER NULL,
            computed_at INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY(user_id, project_id)
        )
    ");

    backfill_subtask_completions($pdo);
}

/**
 * Date every already-done subtask: last timer end, else the parent task's
 * completion date, else its last modification. Best effort by design.
 */
function backfill_subtask_completions(PDO $pdo)
{
    $pdo->exec("
        INSERT INTO timereport_subtask_completions (subtask_id, user_id, completed_at)
        SELECT s.id, s.user_id,
               COALESCE(
                   (SELECT MAX(stt.\"end\") FROM subtask_time_tracking stt WHERE stt.subtask_id = s.id AND stt.\"end\" > 0),
                   NULLIF(t.date_completed, 0),
                   t.date_modification,
                   0
               )
        FROM subtasks s
        JOIN tasks t ON t.id = s.task_id
        WHERE s.status = 2
    ");
}
