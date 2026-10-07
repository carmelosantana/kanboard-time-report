<?php

namespace Kanboard\Plugin\TimeReport\Model;

use Kanboard\Core\Base;
use Kanboard\Model\SubtaskModel;

/**
 * When a subtask was first completed (core keeps no such date). It dates XP
 * for range reports and never stores an amount (spec Kanboard #5379).
 */
class SubtaskCompletionStamp extends Base
{
    public const TABLE = 'timereport_subtask_completions';

    public function sync(int $subtaskId, int $status, int $userId, ?int $now = null): void
    {
        $query = fn () => $this->db->table(self::TABLE)->eq('subtask_id', $subtaskId);

        if ($status !== SubtaskModel::STATUS_DONE) {
            $query()->remove();
            return;
        }

        if ($query()->exists()) {
            $query()->update(['user_id' => $userId]);
            return;
        }

        $this->db->table(self::TABLE)->insert(['subtask_id' => $subtaskId, 'user_id' => $userId, 'completed_at' => $now ?? time()]);
    }
}
