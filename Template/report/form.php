<div class="page-header">
    <h2><?= t('Time Report') ?></h2>
</div>

<form method="post" action="<?= $this->url->href('TimeReportController', 'generate', ['plugin' => 'TimeReport']) ?>" autocomplete="off">
    <?= $this->form->csrf() ?>

    <?= $this->form->label(t('Project'), 'project_id') ?>
    <?= $this->form->select('project_id', $projects, $values, [], ['required']) ?>

    <?= $this->form->date(t('Start date'), 'start_date', $values, [], ['required']) ?>

    <?= $this->form->date(t('End date'), 'end_date', $values, [], ['required']) ?>

    <?= $this->form->label(t('Breakdown'), 'granularity') ?>
    <?= $this->form->select('granularity', ['day' => t('Per day'), 'week' => t('Per week'), 'task' => t('Per task'), 'total' => t('Total only'), 'user' => t('By user')], $values) ?>

    <?php if (! empty($can_report_others)): ?>
        <?= $this->form->label(t('Include'), 'scope') ?>
        <?= $this->form->select('scope', ['self' => t('Just me'), 'all' => t('All users')], $values) ?>
    <?php endif ?>

    <div class="tr-toggles">
        <label><?= $this->form->checkbox('include_detail', t('Include completed-task detail'), 1) ?></label>
        <?php if ($ai_enabled): ?>
            <label><?= $this->form->checkbox('include_ai_summary', t('Add AI narrative summary'), 1) ?></label>
            <?php if (! empty($send_descriptions)): ?>
                <p class="tr-ai-note"><?= t('Task titles, hours, categories, tags, dates, completed subtasks AND task descriptions are sent to the AI provider. Comments are never sent.') ?></p>
            <?php else: ?>
                <p class="tr-ai-note"><?= t('Task titles, hours, categories, tags, dates and completed subtasks are sent to the AI provider. Descriptions and comments are not (an admin can enable descriptions in Settings → Integrations).') ?></p>
            <?php endif ?>
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
