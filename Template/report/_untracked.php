<?php
/**
 * Undated manual time — rendered only when $report['untracked']['task_count'] > 0.
 * Time typed into a subtask before the plugin started dating manual entries: it has no
 * entry date, so it is only ever counted through its task's completion-date total.
 * Sits below the report, collapsed; ticking tasks and choosing a date dates their time.
 * On-screen HTML only (not in Markdown/CSV).
 */
$u = $report['untracked'];
$trHours = $this->helper->timeReport;
?>
<details class="tr-untracked">
    <summary class="tr-untracked-banner">
        <strong><?= t('Undated manual time') ?>:</strong>
        <?= t('%sh on %d task(s), not in this report', $trHours->formatHours((float) $u['total_hours']), (int) $u['task_count']) ?>
    </summary>
    <p>
        <?= t(
            '%d subtask(s) have manually entered time with no entry date. It is only counted once its task is completed, on the completion date, and only if the task has no timer-tracked time. Tick the tasks below and choose a date to report their time on that day instead.',
            (int) $u['subtask_count']
        ) ?>
    </p>
    <form method="post" action="<?= $this->url->href('TimeReportController', 'dateUndated', ['plugin' => 'TimeReport']) ?>" autocomplete="off">
        <?= $this->form->csrf() ?>
        <input type="hidden" name="project_id" value="<?= (int) $report['project_id'] ?>">
        <input type="hidden" name="start_date" value="<?= $this->text->e($report['start_date']) ?>">
        <input type="hidden" name="end_date" value="<?= $this->text->e($report['end_date']) ?>">
        <input type="hidden" name="granularity" value="<?= $this->text->e($report['granularity']) ?>">
        <?php if (! empty($report['include_detail'])): ?><input type="hidden" name="include_detail" value="1"><?php endif ?>
        <?php if (! empty($report['profile_id'])): ?><input type="hidden" name="profile_id" value="<?= $this->text->e($report['profile_id']) ?>"><?php endif ?>
        <?php if (($scope ?? 'self') === 'selected'): ?>
            <?php foreach ($subject ?? [] as $trUid): ?>
                <input type="hidden" name="user_ids[]" value="<?= (int) $trUid ?>">
            <?php endforeach ?>
        <?php else: ?>
            <input type="hidden" name="scope" value="<?= $this->text->e($scope ?? 'self') ?>">
        <?php endif ?>

        <table class="table-fixed tr-untracked-list">
            <thead>
                <tr>
                    <th class="tr-check-col"><input type="checkbox" data-tr-check-all aria-label="<?= t('Select all') ?>" title="<?= t('Select all') ?>"></th>
                    <th><?= t('Ref') ?></th>
                    <th><?= t('Task') ?></th>
                    <th class="tr-num"><?= t('Undated hours') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($u['tasks'] as $tk): ?>
                    <tr>
                        <td class="tr-check-col"><input type="checkbox" name="task_ids[]" value="<?= (int) $tk['task_id'] ?>" aria-label="<?= $this->text->e($tk['title']) ?>"></td>
                        <td><?= $this->text->e($tk['reference']) ?></td>
                        <td><?= $this->url->link($this->text->e($tk['title'] !== '' ? $tk['title'] : ('#' . $tk['task_id'])), 'TaskViewController', 'show', ['task_id' => (int) $tk['task_id']]) ?></td>
                        <td class="tr-num"><?= $this->text->e($trHours->formatHours((float) $tk['hours'])) ?></td>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>

        <div class="tr-untracked-apply">
            <?= $this->form->date(t('Report the ticked time on'), 'entered_date', ['entered_date' => $trHours->fieldDate($report['end_date'])], [], ['required']) ?>
            <button type="submit" class="btn btn-blue"><?= t('Apply date') ?></button>
        </div>
    </form>
</details>
