<?php
/**
 * Range shortcuts: each button fills the start/end date inputs of the form it sits in,
 * in the same format the calendar writes.
 */
$trHelper = $this->helper->timeReport;
?>
<div class="tr-presets">
    <?php foreach ($trHelper->datePresets() as $trPreset): ?>
        <button type="button" class="btn tr-preset" data-tr-preset data-start="<?= $this->text->e($trHelper->fieldDate($trPreset['start'])) ?>" data-end="<?= $this->text->e($trHelper->fieldDate($trPreset['end'])) ?>"><?= $this->text->e($trPreset['label']) ?></button>
    <?php endforeach ?>
    <span class="tr-presets-note">
        <?= $trHelper->weekStart() === 1 ? t('Weeks run Monday to Sunday.') : t('Weeks run Sunday to Saturday.') ?>
        <?= $this->url->link(t('Change'), 'UserViewController', 'integrations', ['user_id' => $this->user->getId()]) ?>
    </span>
</div>
