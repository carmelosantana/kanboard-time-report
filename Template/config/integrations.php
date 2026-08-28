<?php /** TimeReport opt-in on Settings → Integrations. Saved by core ConfigController::save. */ ?>
<div class="form-group">
    <h3><?= t('Time Report') ?></h3>
    <!-- Hidden 0 first so an unchecked box posts "off" (the integrations save adds no defaults). -->
    <input type="hidden" name="timereport_send_descriptions" value="0">
    <label for="timereport_send_descriptions">
        <input type="checkbox" id="timereport_send_descriptions" name="timereport_send_descriptions" value="1"
            <?= isset($values['timereport_send_descriptions']) && $values['timereport_send_descriptions'] == 1 ? 'checked="checked"' : '' ?>>
        <?= t('Send task descriptions to the AI provider for richer summaries') ?>
    </label>
    <p class="form-help">
        <?= t('Off by default. When on, each completed task\'s description is included in the data sent to the configured AI provider (AiConnector), in addition to titles, hours, categories, tags, dates and completed subtasks. Turn this off if descriptions may contain sensitive information. Comments are never sent.') ?>
    </p>
</div>
