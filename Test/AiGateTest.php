<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\TimeReport\Model\AiGate;

class AiGateTest extends Base
{
    public function testFalseBelowPhp84(): void
    {
        $this->assertFalse(AiGate::isReady($this->container, 80399, true));
    }

    public function testFalseWhenConnectorAbsent(): void
    {
        $this->assertFalse(AiGate::isReady($this->container, 80400, false));
    }

    public function testFalseWhenPresentButNoProfileConfigured(): void
    {
        // PHP ok, connector present, but no AiConnector profile → registry isReady() false.
        $this->assertFalse(AiGate::isReady($this->container, 80400, true));
    }

    public function testTrueWhenReady(): void
    {
        $this->container['configModel']->save([
            'aiconnector_profiles' => json_encode([
                ['id' => 'p1', 'label' => 'Test', 'provider' => 'anthropic', 'model' => 'claude-sonnet-4-20250514'],
            ]),
            'aiconnector_key_p1' => 'sk-test-fake-key-for-gate-test',
        ]);
        $this->assertTrue(AiGate::isReady($this->container, 80400, true));
    }
}
