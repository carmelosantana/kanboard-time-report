<h3><?= t('Completed tasks') ?></h3>
<table class="table-fixed tr-detail">
    <thead>
        <tr>
            <th><?= t('Ref') ?></th><th><?= t('Title') ?></th><th class="tr-num"><?= t('Hours') ?></th>
            <th><?= t('Completed') ?></th><th><?= t('Category') ?></th><th><?= t('Tags') ?></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($report['detail'] as $d): ?>
            <tr>
                <td><?= $this->text->e($d['reference']) ?></td>
                <td><?= $this->text->e($d['title']) ?></td>
                <td class="tr-num"><?= $this->text->e($this->helper->timeReport->formatHours((float) $d['hours'])) ?></td>
                <td><?= $this->text->e($d['date_completed']) ?></td>
                <td><?= $this->text->e($d['category']) ?></td>
                <td><?= $this->text->e(implode(', ', $d['tags'])) ?></td>
            </tr>
        <?php endforeach ?>
    </tbody>
</table>
