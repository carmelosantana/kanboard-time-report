<?php /** Range shortcuts: each button fills the start/end date inputs of the form it sits in. */ ?>
<div class="tr-presets">
    <?php foreach ($this->helper->timeReport->datePresets() as $trPreset): ?>
        <button type="button" class="btn tr-preset" data-tr-preset data-start="<?= $this->text->e($trPreset['start']) ?>" data-end="<?= $this->text->e($trPreset['end']) ?>"><?= $this->text->e($trPreset['label']) ?></button>
    <?php endforeach ?>
</div>
