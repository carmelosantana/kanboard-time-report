<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\TimeReport\Model\TimeReportModel;

/**
 * Task 2 — the admin opt-in (timereport_send_descriptions) that governs whether
 * task descriptions are gathered and forwarded to the AI provider. Off by default.
 */
class DescriptionsOptInTest extends Base
{
    private function seedSetting(string $option, string $value): void
    {
        $db = $this->container['db'];
        if ($db->table('settings')->eq('option', $option)->count() > 0) {
            $db->table('settings')->eq('option', $option)->update(['value' => $value]);
        } else {
            $db->table('settings')->insert(['option' => $option, 'value' => $value]);
        }
        $this->container['memoryCache']->flush();
    }

    public function testDefaultsOff(): void
    {
        $model = new TimeReportModel($this->container);
        $this->assertFalse($model->sendDescriptionsEnabled(), 'opt-in must be off by default');
    }

    public function testHonorsSettingWhenOn(): void
    {
        $this->seedSetting('timereport_send_descriptions', '1');
        $model = new TimeReportModel($this->container);
        $this->assertTrue($model->sendDescriptionsEnabled());
    }

    public function testGatherPopulatesDescriptionOnlyWhenOn(): void
    {
        $projectId = (int) $this->container['projectModel']->create(['name' => 'Opt'], 1, true);
        $taskId = (int) $this->container['taskCreationModel']->create([
            'title' => 'Documented', 'project_id' => $projectId, 'owner_id' => 1,
            'description' => 'The real internal notes', 'time_spent' => 2.0,
        ]);
        $this->container['taskStatusModel']->close($taskId);

        // OFF (default): description stays empty.
        $model = new TimeReportModel($this->container);
        $off = $model->report($projectId, date('Y-m-01'), date('Y-m-d'), 'task', true, 1);
        $this->assertSame('', $off['detail'][0]['description']);

        // ON: description flows into the DetailRow.
        $this->seedSetting('timereport_send_descriptions', '1');
        $model2 = new TimeReportModel($this->container);
        $on = $model2->report($projectId, date('Y-m-01'), date('Y-m-d'), 'task', true, 1);
        $this->assertSame('The real internal notes', $on['detail'][0]['description']);
    }

    public function testConfigTemplateAndHookWired(): void
    {
        $plugin = file_get_contents(dirname(__DIR__) . '/Plugin.php');
        $this->assertStringContainsString('template:config:integrations', $plugin, 'checkbox must hook the Integrations settings page');
        $this->assertStringContainsString('TimeReport:config/integrations', $plugin);

        $tpl = file_get_contents(dirname(__DIR__) . '/Template/config/integrations.php');
        $this->assertStringContainsString('timereport_send_descriptions', $tpl);
        // Hidden 0 before the checkbox so unchecking actually turns it OFF.
        $this->assertMatchesRegularExpression(
            '/type="hidden"\s+name="timereport_send_descriptions"\s+value="0"/',
            $tpl,
            'a hidden 0 must precede the checkbox so an unchecked box posts off'
        );
        // Privacy copy must warn that descriptions leave the box.
        $this->assertMatchesRegularExpression('/description/i', $tpl);
    }
}
