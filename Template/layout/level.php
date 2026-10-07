<?php $trl = $this->helper->timeReportProgress->level() ?>
<?php if ($trl !== null): ?>
<span class="tr-level" data-tr-contract="1" data-tr-user="<?= (int) $trl['user_id'] ?>" data-tr-level="<?= (int) $trl['level'] ?>" data-tr-xp="<?= (int) $trl['xp'] ?>" data-tr-next="<?= (int) $trl['next'] ?>"<?php if ($trl['party_xp'] !== null): ?> data-tr-party-xp="<?= (int) $trl['party_xp'] ?>"<?php endif ?>>
    <span class="tr-level__label"><?= t('Level') ?> <?= (int) $trl['level'] ?></span>
</span>
<?php endif ?>
