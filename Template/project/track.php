<?php $trt = $this->helper->timeReportProgress->track((int) $project['id']) ?>
<?php if ($trt !== null): ?>
<section class="tr-track" data-tr-contract="1" data-tr-project="<?= (int) $trt['project_id'] ?>" data-tr-project-pct="<?= (int) $trt['pct'] ?>" aria-label="<?= t('Project progress') ?>">
    <div class="tr-track__project" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int) $trt['pct'] ?>" style="--tr-pct:<?= (int) $trt['pct'] ?>%">
        <span class="tr-track__fill"></span>
        <span class="tr-track__count"><?= (int) $trt['closed'] ?>/<?= (int) $trt['total'] ?></span>
    </div>
    <ol class="tr-track__milestones">
        <?php foreach ($trt['milestones'] as $trms): ?>
        <li class="tr-track__milestone" data-tr-milestone="<?= (int) $trms['task_id'] ?>" data-tr-pct="<?= (int) $trms['pct'] ?>" data-tr-due="<?= $this->text->e($trms['due']) ?>" data-tr-state="open" style="--tr-pct:<?= (int) $trms['pct'] ?>%">
            <span class="tr-track__title"><?= $this->text->e($trms['title']) ?></span>
            <span class="tr-track__count"><?= (int) $trms['closed'] ?>/<?= (int) $trms['total'] ?></span>
        </li>
        <?php endforeach ?>
    </ol>
</section>
<?php endif ?>
