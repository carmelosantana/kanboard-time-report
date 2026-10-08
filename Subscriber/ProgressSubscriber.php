<?php

namespace Kanboard\Plugin\TimeReport\Subscriber;

use Kanboard\Core\Base;
use Kanboard\Model\SubtaskModel;
use Kanboard\Model\TaskModel;
use Kanboard\Plugin\TimeReport\Model\SubtaskCompletionStamp;
use Kanboard\Plugin\TimeReport\Model\XpCache;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/** Keeps completion stamps current and the XP cache honest (spec Kanboard #5383). */
class ProgressSubscriber extends Base implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            SubtaskModel::EVENT_CREATE          => 'onSubtask',
            SubtaskModel::EVENT_UPDATE          => 'onSubtask',
            SubtaskModel::EVENT_DELETE          => 'onChange',
            TaskModel::EVENT_CREATE             => 'onChange',   // CSV import can create closed tasks
            TaskModel::EVENT_CLOSE              => 'onTaskClose',
            TaskModel::EVENT_OPEN               => 'onChange',
            TaskModel::EVENT_UPDATE             => 'onChange',
            TaskModel::EVENT_ASSIGNEE_CHANGE    => 'onChange',
            TaskModel::EVENT_MOVE_PROJECT       => 'onChange',
        ];
    }

    public function onSubtask($event): void
    {
        $subtask = $event['subtask'] ?? null;
        if (is_array($subtask) && isset($subtask['id'])) {
            (new SubtaskCompletionStamp($this->container))->sync((int) $subtask['id'], (int) $subtask['status'], (int) $subtask['user_id']);
        }
        $this->onChange($event);
    }

    /** Core close() marks every subtask done with a direct update that fires no subtask events. */
    public function onTaskClose($event): void
    {
        $taskId = (int) ($event['task_id'] ?? 0);
        if ($taskId > 0) {
            $unstamped = $this->db->table(SubtaskModel::TABLE)
                ->columns(SubtaskModel::TABLE . '.id', SubtaskModel::TABLE . '.user_id')
                ->left(SubtaskCompletionStamp::TABLE, 'c', 'subtask_id', SubtaskModel::TABLE, 'id')
                ->eq(SubtaskModel::TABLE . '.task_id', $taskId)
                ->eq(SubtaskModel::TABLE . '.status', SubtaskModel::STATUS_DONE)
                ->isNull('c.subtask_id')
                ->findAll();

            $stamp = new SubtaskCompletionStamp($this->container);
            foreach ($unstamped ?: [] as $s) {
                $stamp->sync((int) $s['id'], SubtaskModel::STATUS_DONE, (int) ($s['user_id'] ?? 0));
            }
        }
        $this->onChange($event);
    }

    public function onChange($event): void
    {
        (new XpCache($this->container))->invalidateAll();
    }
}
