<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\TimeReport\Plugin;
use Kanboard\Plugin\TimeReport\Model\AiGate;

class PluginTest extends Base
{
    public function testMetadataVersionIs110(): void
    {
        $plugin = new Plugin($this->container);
        $this->assertSame('TimeReport', $plugin->getPluginName());
        $this->assertSame('1.1.0', $plugin->getPluginVersion());
        $this->assertSame('Carmelo Santana', $plugin->getPluginAuthor());
        $this->assertSame('>=1.2.47', $plugin->getCompatibleVersion());
        $this->assertNotEmpty($plugin->getPluginDescription());
        $this->assertStringContainsString('github.com/carmelosantana/kanboard-time-report', $plugin->getPluginHomepage());
    }

    public function testVersionMatchesPluginJson(): void
    {
        $json = json_decode(file_get_contents(dirname(__DIR__) . '/plugin.json'), true);
        $plugin = new Plugin($this->container);
        $this->assertSame($json['version'], $plugin->getPluginVersion(), 'Plugin.php version must equal plugin.json version');
        $this->assertSame('1.1.0', $json['version']);
    }

    public function testPhpGate(): void
    {
        $plugin = new Plugin($this->container);
        $this->assertTrue($plugin->isPhpCompatible(80400));
        $this->assertFalse($plugin->isPhpCompatible(80399));
    }

    public function testInitializeRunsAndSetsAiDisabledWithoutProfile(): void
    {
        $plugin = new Plugin($this->container);
        $plugin->initialize();
        // No AiConnector profile configured → gate closed.
        $this->assertFalse($plugin->isAiEnabled());
    }

    public function testInitializeEnablesAiWhenProfileConfigured(): void
    {
        $this->container['configModel']->save([
            'aiconnector_profiles' => json_encode([
                ['id' => 'p1', 'label' => 'Test', 'provider' => 'anthropic', 'model' => 'claude-sonnet-4-20250514'],
            ]),
            'aiconnector_key_p1' => 'sk-test-fake-key-for-gate-test',
        ]);
        $plugin = new Plugin($this->container);
        $plugin->initialize();
        $this->assertTrue($plugin->isAiEnabled());
    }

    /**
     * Regression: the TimeReportHelper must be reachable through the framework Helper
     * container as a PROPERTY ($this->helper->timeReport->method()), which is how the
     * controller and templates call it. Kanboard's Helper exposes registered helpers via
     * __get only — there is no __call — so a method-style access ($this->helper->timeReport())
     * fatals at runtime even though the helper's own unit tests (which instantiate it
     * directly) stay green. This locks the registration + property-access contract.
     */
    public function testTimeReportHelperRegisteredAndCallableAsProperty(): void
    {
        $plugin = new Plugin($this->container);
        $plugin->initialize();

        $helper = $this->container['helper']->timeReport; // property access, exactly as the views do
        $this->assertInstanceOf(
            \Kanboard\Plugin\TimeReport\Helper\TimeReportHelper::class,
            $helper,
            'timeReport helper must be registered on the Helper container'
        );
        $this->assertSame('1.50', $helper->formatHours(1.5), 'helper methods must be callable through the container');
    }
}
