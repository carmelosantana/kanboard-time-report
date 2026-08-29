<div class="page-header">
    <h2><?= t('Time Report') ?> — <?= $this->text->e($report['project_name']) ?></h2>
</div>

<?php if (! empty($report['scope_denied'])): ?>
    <div class="alert alert-error">
        <?= t('You do not have permission to include other users in this project. Showing your own hours.') ?>
    </div>
<?php endif ?>

<div class="tr-summary">
    <p>
        <strong><?= t('Range') ?>:</strong> <?= $this->text->e($report['start_date']) ?> → <?= $this->text->e($report['end_date']) ?>
        &nbsp;·&nbsp;
        <?php $trTotal = $this->helper->timeReport->formatHours((float) $report['total_hours']); ?>
        <strong><?= t('Total hours') ?>:</strong> <span class="tr-copy-num" data-tr-copyval="<?= $this->text->e($trTotal) ?>" data-tr-copied="<?= t('Copied') ?>" role="button" tabindex="0" title="<?= t('Click to copy') ?>"><?= $this->text->e($trTotal) ?></span>
    </p>
    <div class="tr-actions">
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
