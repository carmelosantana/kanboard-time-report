<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\TimeReport\Helper\ProgressHelper;

class TpbNoticeTest extends Base
{
    protected function setUp(): void
    {
        parent::setUp();
        // Production registers this in PluginProvider; the unit-test container does not. Unscanned = no plugins.
        $this->container['pluginLoader'] = new \Kanboard\Core\Plugin\Loader($this->container);
        $this->container['helper']->register('timeReportProgress', ProgressHelper::class);
    }

    private function loadFakeTaskProgressBar(): void
    {
        $loader = $this->container['pluginLoader'];
        $prop = new \ReflectionProperty($loader, 'plugins');
        $prop->setValue($loader, $prop->getValue($loader) + ['TaskProgressBar' => new \stdClass()]);
    }

    private function actAs(string $role): int
    {
        $uid = (int) $this->container['userModel']->create(['username' => 'u_' . $role, 'password' => 'x1234567', 'role' => $role]);
        $this->container['userSession']->initialize($this->container['userModel']->getById($uid));
        return $uid;
    }

    private function helper(): ProgressHelper
    {
        return new ProgressHelper($this->container);
    }

    public function testHiddenWhenTaskProgressBarIsAbsent(): void
    {
        $this->actAs('app-admin');
        $this->assertFalse($this->helper()->tpbNoticeVisible());
    }

    public function testVisibleToAdminsWhileTaskProgressBarIsLoaded(): void
    {
        $this->loadFakeTaskProgressBar();
        $this->actAs('app-admin');
        $this->assertTrue($this->helper()->tpbNoticeVisible());

        $html = $this->container['template']->render('TimeReport:config/tpb_notice');
        $this->assertStringContainsString('plugins/TaskProgressBar', $html);
        $this->assertStringContainsString('name="csrf_token"', $html);
        $this->assertStringContainsString('action=dismissTpbNotice', $html); // pretty route needs URL rewriting; covered below
    }

    public function testHiddenFromNonAdmins(): void
    {
        $this->loadFakeTaskProgressBar();
        $this->actAs('app-user');
        $this->assertFalse($this->helper()->tpbNoticeVisible());
    }

    public function testHiddenOnceDismissed(): void
    {
        $this->loadFakeTaskProgressBar();
        $uid = $this->actAs('app-admin');
        $this->container['userMetadataModel']->save($uid, [ProgressHelper::TPB_DISMISS_KEY => '1']);
        $this->assertFalse($this->helper()->tpbNoticeVisible());
    }

    public function testDismissActionChecksCsrfAndIsRouted(): void
    {
        $src = file_get_contents(dirname(__DIR__) . '/Controller/TimeReportController.php');
        $this->assertMatchesRegularExpression('/function dismissTpbNotice\(\): void\s*\{\s*\$this->checkCSRFForm\(\);/', $src);
        $this->assertStringContainsString("'timereport/tpb-dismiss'", file_get_contents(dirname(__DIR__) . '/Plugin.php'));
    }
}
