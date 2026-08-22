<?php
/**
 * Untracked subtask-time warning — rendered only when $report['untracked']['task_count'] > 0.
 * Advisory guidance; on-screen HTML only (not in Markdown/CSV). CSP-safe, no inline JS.
 */
$u = $report['untracked'];
?>
<div class="tr-untracked">
    <p class="tr-untracked-banner">
        <strong>&#9888; <?= t('Untracked subtask time') ?>:</strong>
        <?= t(
            '%d subtask(s) on %d task(s) have %sh of manually-entered time that is not date-tracked, so it is not counted here — log it with the subtask timer or add it to the task Time spent.',
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
                <th class="tr-num"><?= t('Untracked hours') ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($u['tasks'] as $tk): ?>
                <tr>
                    <td><?= $this->text->e($tk['reference']) ?></td>
                    <td><?= $this->text->e($tk['title'] !== '' ? $tk['title'] : ('#' . $tk['task_id'])) ?></td>
                    <td class="tr-num"><?= $this->text->e($this->helper->timeReport->formatHours((float) $tk['hours'])) ?></td>
                </tr>
            <?php endforeach ?>
        </tbody>
    </table>
</div>
