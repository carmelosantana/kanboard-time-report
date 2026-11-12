<?php
/**
 * Undated manual time — rendered only when $report['untracked']['task_count'] > 0.
 * Time typed into a subtask before the plugin started dating manual entries: it has no
 * entry date, so no range can include it. On-screen HTML only (not in Markdown/CSV).
 */
$u = $report['untracked'];
?>
<div class="tr-untracked">
    <p class="tr-untracked-banner">
        <strong>&#9888; <?= t('Undated manual time') ?>:</strong>
        <?= t(
            '%d subtask(s) on %d task(s) have %sh of manually entered time with no entry date, so it is not counted in any report. To count it, clear the time on the subtask and enter it again: it will be dated the day you re-enter it.',
            (int) $u['subtask_count'],
            (int) $u['task_count'],
            $this->helper->timeReport->formatHours((float) $u['total_hours'])
        ) ?>
    </p>
    <table class="table-fixed tr-untracked-list">
        <thead>
            <tr>
                <th><?= t('Ref') ?></th>
                <th><?= t('Task') ?></th>
                <th class="tr-num"><?= t('Undated hours') ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($u['tasks'] as $tk): ?>
                <tr>
                    <td><?= $this->text->e($tk['reference']) ?></td>
                    <td><?= $this->url->link($this->text->e($tk['title'] !== '' ? $tk['title'] : ('#' . $tk['task_id'])), 'TaskViewController', 'show', ['task_id' => (int) $tk['task_id']]) ?></td>
                    <td class="tr-num"><?= $this->text->e($this->helper->timeReport->formatHours((float) $tk['hours'])) ?></td>
                </tr>
            <?php endforeach ?>
        </tbody>
    </table>
</div>
