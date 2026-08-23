<?php
/** Two TimeReport entry links in the project ≡ menu (hook template:project:dropdown; core passes $project). */
?>
<li>
    <?= $this->url->icon('bolt', t('Generate report'), 'TimeReportController', 'view', ['plugin' => 'TimeReport', 'project_id' => $project['id']]) ?>
</li>
<li>
    <?= $this->url->icon('clock-o', t('Time Report'), 'TimeReportController', 'index', ['plugin' => 'TimeReport', 'project_id' => $project['id']]) ?>
</li>
