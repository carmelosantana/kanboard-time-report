<?php $isTask = $report['granularity'] === 'task'; ?>
<table class="table-fixed tr-breakdown">
    <thead>
        <tr>
            <th><?= $isTask ? t('Task') : t('Period') ?></th>
            <th class="tr-num"><?= t('Hours') ?></th>
            <?php if (! $isTask): ?><th class="tr-num"><?= t('Tasks') ?></th><?php endif ?>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($report['breakdown'] as $row): ?>
            <tr>
                <td><?= $this->text->e($row['label']) ?></td>
                <td class="tr-num"><?= $this->text->e($this->helper->timeReport()->formatHours((float) $row['hours'])) ?></td>
                <?php if (! $isTask): ?><td class="tr-num"><?= (int) $row['task_count'] ?></td><?php endif ?>
            </tr>
        <?php endforeach ?>
    </tbody>
</table>
