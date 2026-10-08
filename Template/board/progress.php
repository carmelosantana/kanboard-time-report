<?php $trm = $this->helper->timeReportProgress->card($task) ?>
<?php if ($trm['has_subtasks'] || $trm['has_estimate']): ?>
<div class="tr-progress" data-tr-contract="1" data-tr-task="<?= (int) $trm['task_id'] ?>"<?php if ($trm['has_subtasks']): ?> data-tr-subtasks-done="<?= (int) $trm['subtasks_done'] ?>" data-tr-subtasks-total="<?= (int) $trm['subtasks_total'] ?>" data-tr-pct="<?= (int) $trm['pct'] ?>"<?php endif ?><?php if ($trm['has_estimate']): ?> data-tr-time-spent="<?= $this->helper->timeReportProgress->num($trm['time_spent']) ?>" data-tr-time-est="<?= $this->helper->timeReportProgress->num($trm['time_estimated']) ?>" data-tr-time-state="<?= $this->text->e($trm['time_state']) ?>"<?php endif ?>>
    <?php if ($trm['has_subtasks']): ?>
    <div class="tr-progress__bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int) $trm['pct'] ?>" aria-label="<?= t('Subtasks completed') ?>" style="--tr-pct:<?= (int) $trm['pct'] ?>%">
        <span class="tr-progress__fill"></span>
        <span class="tr-progress__label"><?= (int) $trm['subtasks_done'] ?>/<?= (int) $trm['subtasks_total'] ?></span>
    </div>
    <?php endif ?>
    <?php if ($trm['has_estimate']): ?>
    <div class="tr-progress__time tr-progress__time--<?= $this->text->e($trm['time_state']) ?>" style="--tr-pct:<?= (int) $trm['time_pct'] ?>%" title="<?= t('Time spent and estimated') ?>">
        <span class="tr-progress__track"><span class="tr-progress__fill"></span></span>
        <span class="tr-progress__label"><?= $this->helper->timeReportProgress->num($trm['time_spent']) ?>/<?= $this->helper->timeReportProgress->num($trm['time_estimated']) ?>h</span>
    </div>
    <?php endif ?>
</div>
<?php endif ?>
