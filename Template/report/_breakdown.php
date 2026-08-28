<?php
$isTask = $report['granularity'] === 'task';
$trLabel = match ($report['granularity']) {
    'task'  => t('Task'),
    'user'  => t('User'),
    default => t('Period'),
};
?>
<table class="table-fixed tr-breakdown">
    <thead>
        <tr>
            <th><?= $trLabel ?></th>
            <th class="tr-num"><?= t('Hours') ?></th>
            <?php if (! $isTask): ?><th class="tr-num"><?= t('Tasks') ?></th><?php endif ?>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($report['breakdown'] as $row): ?>
            <?php $trHours = $this->helper->timeReport->formatHours((float) $row['hours']); ?>
            <tr>
                <td><?= $this->text->e($this->helper->timeReport->withWeekday($row['label'])) ?></td>
                <td class="tr-num tr-copy-num" data-tr-copyval="<?= $this->text->e($trHours) ?>" data-tr-copied="<?= t('Copied') ?>" role="button" tabindex="0" title="<?= t('Click to copy') ?>"><?= $this->text->e($trHours) ?></td>
                <?php if (! $isTask): ?><td class="tr-num"><?= (int) $row['task_count'] ?></td><?php endif ?>
            </tr>
        <?php endforeach ?>
    </tbody>
</table>
