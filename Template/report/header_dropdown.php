<?php
/** Entry-point link in the header user dropdown (hook template:header:dropdown). */
?>
<li>
    <?= $this->url->icon('clock-o', t('Time Report'), 'TimeReportController', 'index', ['plugin' => 'TimeReport']) ?>
</li>
