<?php
/**
 * The TimeReport entry in the project ≡ menu (hook template:project:dropdown; core passes $project).
 * One link: this project's report for this month. Its filters change in place ("Edit filters").
 */
?>
<li>
    <?= $this->url->icon('clock-o', t('Time Report'), 'TimeReportController', 'view', ['plugin' => 'TimeReport', 'project_id' => $project['id']]) ?>
</li>
