<?php if ($this->helper->timeReportProgress->tpbNoticeVisible()): ?>
<div class="tr-tpb-notice alert alert-info">
    <form method="post" action="<?= $this->url->href('TimeReportController', 'dismissTpbNotice', ['plugin' => 'TimeReport']) ?>" class="tr-inline-form">
        <?= $this->form->csrf() ?>
        <?= t('TimeReport now shows subtask progress on board cards. TaskProgressBar is redundant: remove the %s folder.', 'plugins/TaskProgressBar') ?>
        <button type="submit" class="btn btn-small"><?= t('Dismiss') ?></button>
    </form>
</div>
<?php endif ?>
