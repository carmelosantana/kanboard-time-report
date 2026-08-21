<div class="page-header">
    <h2><?= t('Time Report') ?></h2>
</div>

<form method="post" action="<?= $this->url->href('TimeReportController', 'generate', ['plugin' => 'TimeReport']) ?>" autocomplete="off">
    <?= $this->form->csrf() ?>

    <?= $this->form->label(t('Project'), 'project_id') ?>
    <?= $this->form->select('project_id', $projects, $values, [], ['required']) ?>

    <?= $this->form->label(t('Start date'), 'start_date') ?>
    <?= $this->form->text('start_date', $values, [], ['placeholder="YYYY-MM-DD"', 'required']) ?>

    <?= $this->form->label(t('End date'), 'end_date') ?>
    <?= $this->form->text('end_date', $values, [], ['placeholder="YYYY-MM-DD"', 'required']) ?>

    <?= $this->form->label(t('Breakdown'), 'granularity') ?>
    <?= $this->form->select('granularity', ['day' => t('Per day'), 'week' => t('Per week'), 'task' => t('Per task'), 'total' => t('Total only')], $values) ?>

    <div class="tr-toggles">
        <label><?= $this->form->checkbox('include_detail', t('Include completed-task detail'), 1) ?></label>
        <?php if ($ai_enabled): ?>
            <label><?= $this->form->checkbox('include_ai_summary', t('Add AI narrative summary'), 1) ?></label>
            <p class="tr-ai-note"><?= t('Only completed-task titles, hours, categories, tags and dates are sent to the AI provider.') ?></p>
            <?php if (! empty($profiles)): ?>
                <?= $this->form->label(t('AI profile'), 'profile_id') ?>
                <?= $this->form->select('profile_id', array_column($profiles, 'label', 'id')) ?>
            <?php endif ?>
        <?php else: ?>
            <p class="tr-ai-note tr-ai-off"><?= t('AI summary unavailable (install and configure the AiConnector plugin to enable it).') ?></p>
        <?php endif ?>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-blue"><?= t('Generate report') ?></button>
    </div>
</form>
