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
$trCols = 4 + ($isTask ? 0 : 1) + ($trExpandable ? 1 : 0);
$trNum = fn (float $h): string => $this->helper->timeReport->formatHours($h);
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
<?php if (empty($report['breakdown'])): ?>
    <p class="alert"><?= t('No hours were logged in this range.') ?></p>
<?php else: ?>
<table class="table-fixed tr-breakdown">
    <thead>
        <tr>
            <?php if ($trExpandable): ?><th class="tr-expander-col"></th><?php endif ?>
            <th><?= $trLabel ?></th>
            <th class="tr-num"><?= t('Tracked') ?></th>
            <th class="tr-num"><?= t('Manual') ?></th>
            <th class="tr-num"><?= t('Total') ?></th>
            <?php if (! $isTask): ?><th class="tr-num"><?= t('Tasks') ?></th><?php endif ?>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($report['breakdown'] as $row): ?>
            <?php
            $trManual = (float) ($row['manual_hours'] ?? 0.0);
            $trCells  = [(float) ($row['tracked_hours'] ?? ((float) $row['hours'] - $trManual)), $trManual, (float) $row['hours']];
            $trRowLabel = $this->helper->timeReport->withWeekday($row['label']);
            ?>
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
                <td>
                    <?php if ($isTask): ?>
                        <?= $this->url->link($this->text->e($trRowLabel), 'TaskViewController', 'show', ['task_id' => (int) $row['key']]) ?>
                    <?php else: ?>
                        <?= $this->text->e($trRowLabel) ?>
                    <?php endif ?>
                </td>
                <?php foreach ($trCells as $trCell): ?>
                    <td class="tr-num tr-copy-num" data-tr-copyval="<?= $this->text->e($trNum($trCell)) ?>" data-tr-copied="<?= t('Copied') ?>" role="button" tabindex="0" title="<?= t('Click to copy') ?>"><?= $this->text->e($trNum($trCell)) ?></td>
                <?php endforeach ?>
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
    <tfoot>
        <tr class="tr-total-row">
            <?php if ($trExpandable): ?><th class="tr-expander-col"></th><?php endif ?>
            <th><?= t('Total') ?></th>
            <th class="tr-num"><?= $this->text->e($trNum((float) ($report['tracked_hours'] ?? 0.0))) ?></th>
            <th class="tr-num"><?= $this->text->e($trNum((float) ($report['manual_hours'] ?? 0.0))) ?></th>
            <th class="tr-num"><?= $this->text->e($trNum((float) $report['total_hours'])) ?></th>
            <?php if (! $isTask): ?><th></th><?php endif ?>
        </tr>
    </tfoot>
</table>
<?php endif ?>
