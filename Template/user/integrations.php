<?php
/**
 * TimeReport preferences on My profile → Integrations. Saved by core
 * UserViewController::integrations into the user's metadata.
 */
use Kanboard\Plugin\TimeReport\Model\TimeReportModel;

$trKey = TimeReportModel::WEEK_START_KEY;
$trValues = [$trKey => ($values[$trKey] ?? '0') === '1' ? '1' : '0'];
?>
<h3><?= t('Time Report') ?></h3>
<div class="panel">
    <?= $this->form->label(t('Week starts on'), $trKey) ?>
    <?= $this->form->select($trKey, ['0' => t('Sunday'), '1' => t('Monday')], $trValues) ?>
    <p class="form-help"><?= t('Used by the "This week" and "Last week" shortcuts and the per-week breakdown.') ?></p>
    <div class="form-actions">
        <button type="submit" class="btn btn-blue"><?= t('Save') ?></button>
    </div>
</div>
