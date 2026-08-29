<?php
$isTask = $report['granularity'] === 'task';
$trLabel = match ($report['granularity']) {
    'task'  => t('Task'),
    'user'  => t('User'),
    default => t('Period'),
};
// Per-row AI summaries only apply to the task/day/week breakdowns and only when the
// AI gate is open. user/total keep the single report-level summary block (§6.6).
$trExpandable = ! empty($ai_enabled) && in_array($report['granularity'], ['task', 'day', 'week'], true);
$trCols = 2 + ($isTask ? 0 : 1) + ($trExpandable ? 1 : 0);
?>
<?php if ($trExpandable): ?>
    <!-- Hidden context for the per-row summary AJAX; the JS clones this form's FormData. -->
    <form id="tr-summary-context" class="tr-hidden"
          data-url="<?= $this->url->href('TimeReportController', 'rowSummary', ['plugin' => 'TimeReport']) ?>"
          data-outdated="<?= t('may be outdated') ?>"
          data-regenerate="<?= t('Regenerate') ?>"
          data-loading="<?= t('Generating summary…') ?>"
          data-error="<?= t('The summary could not be generated.') ?>"
          data-empty="<?= t('No summary available.') ?>">
        <?= $this->form->csrf() ?>
        <input type="hidden" name="project_id" value="<?= (int) $report['project_id'] ?>">
        <input type="hidden" name="start_date" value="<?= $this->text->e($report['start_date']) ?>">
        <input type="hidden" name="end_date" value="<?= $this->text->e($report['end_date']) ?>">
        <input type="hidden" name="granularity" value="<?= $this->text->e($report['granularity']) ?>">
        <?php if (! empty($report['profile_id'])): ?>
            <!-- The selected AI profile drives generation on a miss/force; the cache key
                 stays row + content hash only (D6), so this never affects cache identity. -->
            <input type="hidden" name="profile_id" value="<?= $this->text->e($report['profile_id']) ?>">
        <?php endif ?>
        <?php foreach ($report['subject_user_ids'] as $trUid): ?>
            <input type="hidden" name="user_ids[]" value="<?= (int) $trUid ?>">
        <?php endforeach ?>
    </form>
<?php endif ?>
<table class="table-fixed tr-breakdown">
    <thead>
        <tr>
            <?php if ($trExpandable): ?><th class="tr-expander-col"></th><?php endif ?>
            <th><?= $trLabel ?></th>
            <th class="tr-num"><?= t('Hours') ?></th>
            <?php if (! $isTask): ?><th class="tr-num"><?= t('Tasks') ?></th><?php endif ?>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($report['breakdown'] as $row): ?>
            <?php $trHours = $this->helper->timeReport->formatHours((float) $row['hours']); ?>
            <tr>
                <?php if ($trExpandable): ?>
                    <td class="tr-expander-col">
                        <button type="button" class="tr-expander" data-tr-row-toggle
                                data-row-key="<?= $this->text->e($row['key']) ?>"
                                aria-expanded="false" title="<?= t('Show AI summary') ?>">
                            <i class="fa fa-chevron-right" aria-hidden="true"></i>
                        </button>
                    </td>
                <?php endif ?>
                <td><?= $this->text->e($this->helper->timeReport->withWeekday($row['label'])) ?></td>
                <td class="tr-num tr-copy-num" data-tr-copyval="<?= $this->text->e($trHours) ?>" data-tr-copied="<?= t('Copied') ?>" role="button" tabindex="0" title="<?= t('Click to copy') ?>"><?= $this->text->e($trHours) ?></td>
                <?php if (! $isTask): ?><td class="tr-num"><?= (int) $row['task_count'] ?></td><?php endif ?>
            </tr>
            <?php if ($trExpandable): ?>
                <tr class="tr-summary-row" data-row-key="<?= $this->text->e($row['key']) ?>" hidden>
                    <td colspan="<?= (int) $trCols ?>">
                        <div class="tr-summary-panel" data-loaded="0"></div>
                    </td>
                </tr>
            <?php endif ?>
        <?php endforeach ?>
    </tbody>
</table>
