<?php
$trGranLabels = [
    'day'   => t('Per day'),
    'week'  => t('Per week'),
    'task'  => t('Per task'),
    'total' => t('Total only'),
    'user'  => t('By user'),
];
$trScope = ! empty($report['multi_user']) ? 'all' : 'self';
$trValues = [
    'project_id'  => $report['project_id'],
    'start_date'  => $report['start_date'],
    'end_date'    => $report['end_date'],
    'granularity' => $report['granularity'],
    'scope'       => $trScope,
];
$trExpandable = ! empty($ai_enabled) && in_array($report['granularity'], ['task', 'day', 'week'], true);
$trTotal = $this->helper->timeReport->formatHours((float) $report['total_hours']);
?>
<div class="page-header">
    <h2><?= t('Time Report') ?> — <?= $this->text->e($report['project_name']) ?></h2>
</div>

<?php if (! empty($report['scope_denied'])): ?>
    <div class="alert alert-error">
        <?= t('You do not have permission to include other users in this project. Showing your own hours.') ?>
    </div>
<?php endif ?>

<div class="tr-controlbar">
    <div class="tr-controlbar-summary">
        <span class="tr-controlbar-crumbs">
            <strong><?= $this->text->e($report['project_name']) ?></strong>
            &nbsp;·&nbsp; <?= $this->text->e($report['start_date']) ?> → <?= $this->text->e($report['end_date']) ?>
            &nbsp;·&nbsp; <?= $this->text->e($trGranLabels[$report['granularity']] ?? $report['granularity']) ?>
            &nbsp;·&nbsp; <?= ! empty($report['multi_user']) ? t('All users') : t('Just me') ?>
            <?php if (! empty($ai_enabled)): ?>&nbsp;·&nbsp; <span class="tr-controlbar-ai">+AI</span><?php endif ?>
            &nbsp;·&nbsp; <strong><?= t('Total hours') ?>:</strong>
            <span class="tr-copy-num" data-tr-copyval="<?= $this->text->e($trTotal) ?>" data-tr-copied="<?= t('Copied') ?>" role="button" tabindex="0" title="<?= t('Click to copy') ?>"><?= $this->text->e($trTotal) ?></span>
        </span>
        <button type="button" class="btn tr-edit-filters" data-tr-edit-filters aria-expanded="false"><?= t('Edit filters') ?></button>
    </div>

    <form id="tr-filters" class="tr-filters" hidden method="post" action="<?= $this->url->href('TimeReportController', 'generate', ['plugin' => 'TimeReport']) ?>" autocomplete="off">
        <?= $this->form->csrf() ?>
        <?= $this->form->label(t('Project'), 'project_id') ?>
        <?= $this->form->select('project_id', $projects, $trValues, [], ['required']) ?>

        <?= $this->form->date(t('Start date'), 'start_date', $trValues, [], ['required']) ?>
        <?= $this->form->date(t('End date'), 'end_date', $trValues, [], ['required']) ?>

        <?= $this->form->label(t('Breakdown'), 'granularity') ?>
        <?= $this->form->select('granularity', $trGranLabels, $trValues) ?>

        <?php if (! empty($can_report_others)): ?>
            <?= $this->form->label(t('Include'), 'scope') ?>
            <?= $this->form->select('scope', ['self' => t('Just me'), 'all' => t('All users')], $trValues) ?>
        <?php endif ?>

        <div class="tr-toggles">
            <label><?= $this->form->checkbox('include_detail', t('Include completed-task detail'), 1, ! empty($report['include_detail'])) ?></label>
            <?php if (! empty($ai_enabled)): ?>
                <label><?= $this->form->checkbox('include_ai_summary', t('Add report-level AI summary'), 1, ! empty($report['ai'])) ?></label>
                <?php if (! empty($profiles)): ?>
                    <?= $this->form->label(t('AI profile'), 'profile_id') ?>
                    <?= $this->form->select('profile_id', array_column($profiles, 'label', 'id')) ?>
                <?php endif ?>
            <?php endif ?>
        </div>
    </form>

    <div class="tr-actions">
        <button type="submit" form="tr-filters" class="btn btn-blue"><?= t('Update') ?></button>
        <?php if ($trExpandable): ?>
            <button type="button" class="btn tr-generate-all" data-tr-generate-all
                    data-progress="<?= t('Generating summaries…') ?>"
                    data-done="<?= t('Generate all summaries') ?>"><?= t('Generate all summaries') ?></button>
        <?php endif ?>
        <button type="button" class="btn" data-tr-copy data-tr-copied="<?= t('Copied') ?>"><?= t('Copy as Markdown') ?></button>
        <?php if (empty($report['scope_denied'])): ?>
        <form method="post" class="tr-inline-form" action="<?= $this->url->href('TimeReportController', 'exportCsv', ['plugin' => 'TimeReport']) ?>">
            <?= $this->form->csrf() ?>
            <input type="hidden" name="project_id" value="<?= (int) $report['project_id'] ?>">
            <input type="hidden" name="start_date" value="<?= $this->text->e($report['start_date']) ?>">
            <input type="hidden" name="end_date" value="<?= $this->text->e($report['end_date']) ?>">
            <input type="hidden" name="granularity" value="<?= $this->text->e($report['granularity']) ?>">
            <input type="hidden" name="include_detail" value="<?= ! empty($report['include_detail']) ? 1 : 0 ?>">
            <?php foreach ($report['subject_user_ids'] as $trUid): ?>
                <input type="hidden" name="user_ids[]" value="<?= (int) $trUid ?>">
            <?php endforeach ?>
            <button type="submit" class="btn"><?= t('Export CSV') ?></button>
        </form>
        <?php endif ?>
    </div>
</div>

<?php if (! empty($report['untracked']['task_count'])): ?>
    <?= $this->render('TimeReport:report/_untracked', ['report' => $report]) ?>
<?php endif ?>

<?php if (! empty($report['participants']) && count($report['participants']) > 1): ?>
    <?= $this->render('TimeReport:report/_users', ['report' => $report]) ?>
<?php endif ?>

<?= $this->render('TimeReport:report/_breakdown', ['report' => $report, 'ai_enabled' => ! empty($ai_enabled)]) ?>

<?php if (! empty($report['include_detail']) && ! empty($report['detail'])): ?>
    <?= $this->render('TimeReport:report/_detail', ['report' => $report]) ?>
<?php endif ?>

<?php if (! empty($report['ai']) && is_array($report['ai'])): ?>
    <div class="tr-ai">
        <h3><?= t('Summary') ?></h3>
        <?php if (! empty($report['ai']['error'])): ?>
            <p class="tr-ai-error"><?= $this->text->e($report['ai']['error']) ?></p>
        <?php else: ?>
            <p><?= $this->text->e($report['ai']['summary']) ?></p>
            <?php if (! empty($report['ai']['highlights'])): ?>
                <ul>
                    <?php foreach ($report['ai']['highlights'] as $h): ?>
                        <li><?= $this->text->e($h) ?></li>
                    <?php endforeach ?>
                </ul>
            <?php endif ?>
        <?php endif ?>
        <p class="tr-ai-note"><?= t('AI-proposed — review before sharing.') ?></p>
    </div>
<?php endif ?>

<textarea id="tr-markdown" class="tr-hidden" readonly aria-hidden="true"><?= $this->text->e($markdown) ?></textarea>

<p><a href="<?= $this->url->href('TimeReportController', 'index', ['plugin' => 'TimeReport']) ?>">&larr; <?= t('New report') ?></a></p>
