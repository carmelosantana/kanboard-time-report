<?php $trMulti = ! empty($report['multi_user']); ?>
<h3><?= t('Completed tasks') ?></h3>
<table class="table-fixed tr-detail">
    <thead>
        <tr>
            <th><?= t('Ref') ?></th><th><?= t('Title') ?></th>
            <?php if ($trMulti): ?><th><?= t('Assignee') ?></th><?php endif ?>
            <th class="tr-num"><?= t('Hours') ?></th>
            <th><?= t('Completed') ?></th><th><?= t('Category') ?></th><th><?= t('Tags') ?></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($report['detail'] as $d): ?>
            <?php $trHours = $this->helper->timeReport->formatHours((float) $d['hours']); ?>
            <tr>
                <td><?= $this->text->e($d['reference']) ?></td>
                <td><?= $this->text->e($d['title']) ?></td>
                <?php if ($trMulti): ?><td><?= $this->text->e($d['assignee'] ?? '') ?></td><?php endif ?>
                <td class="tr-num tr-copy-num" data-tr-copyval="<?= $this->text->e($trHours) ?>" data-tr-copied="<?= t('Copied') ?>" role="button" tabindex="0" title="<?= t('Click to copy') ?>"><?= $this->text->e($trHours) ?></td>
                <td><?= $this->text->e($this->helper->timeReport->withWeekday($d['date_completed'])) ?></td>
                <td><?= $this->text->e($d['category']) ?></td>
                <td><?= $this->text->e(implode(', ', $d['tags'])) ?></td>
            </tr>
        <?php endforeach ?>
    </tbody>
</table>
