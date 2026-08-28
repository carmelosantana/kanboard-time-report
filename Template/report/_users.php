<?php /** Refine panel: which participants the report covers. POSTs back to generate. */ ?>
<div class="tr-users">
    <h3><?= t('Users') ?></h3>
    <form method="post" action="<?= $this->url->href('TimeReportController', 'generate', ['plugin' => 'TimeReport']) ?>">
        <?= $this->form->csrf() ?>
        <input type="hidden" name="project_id" value="<?= (int) $report['project_id'] ?>">
        <input type="hidden" name="start_date" value="<?= $this->text->e($report['start_date']) ?>">
        <input type="hidden" name="end_date" value="<?= $this->text->e($report['end_date']) ?>">
        <input type="hidden" name="granularity" value="<?= $this->text->e($report['granularity']) ?>">
        <?php if (! empty($report['include_detail'])): ?>
            <input type="hidden" name="include_detail" value="1">
        <?php endif ?>

        <ul class="tr-user-list">
            <?php $trSelected = array_map('intval', $report['subject_user_ids']); ?>
            <?php foreach ($report['participants'] as $trUid => $trPerson): ?>
                <li>
                    <label>
                        <input type="checkbox" name="user_ids[]" value="<?= (int) $trUid ?>"
                            <?= in_array((int) $trUid, $trSelected, true) ? 'checked' : '' ?>>
                        <?= $this->text->e($trPerson['name']) ?>
                        — <?= $this->text->e($this->helper->timeReport->formatHours((float) $trPerson['hours'])) ?><?= t('h') ?>
                    </label>
                </li>
            <?php endforeach ?>
        </ul>

        <button type="submit" class="btn"><?= t('Update report') ?></button>
    </form>
</div>
