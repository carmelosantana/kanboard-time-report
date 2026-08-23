<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;

class PluginMetaTest extends Base
{
    private function json(): array
    {
        return json_decode(file_get_contents(dirname(__DIR__) . '/plugin.json'), true);
    }

    public function testVersionIsExactly120(): void
    {
        $this->assertSame('1.2.0', $this->json()['version']);
    }

    public function testNameAndCompat(): void
    {
        $j = $this->json();
        $this->assertSame('TimeReport', $j['name']);
        $this->assertSame('>=1.2.47', $j['kanboard_version']);
        $this->assertSame('>=8.4', $j['php_version']);
        $this->assertSame('MIT', $j['license']);
    }

    /** The dependency-array trap: recommends must be an ARRAY of objects with a bare min_version. */
    public function testRecommendsArrayShape(): void
    {
        $j = $this->json();
        $this->assertArrayNotHasKey('requires', $j, 'AiConnector must be recommends, never requires');
        $this->assertSame(['AiConnector'], array_column($j['recommends'], 'plugin'));
        $this->assertSame('1.0.0', $j['recommends'][0]['min_version'], 'bare semver, no ">=" prefix');
        $this->assertStringStartsNotWith('>=', $j['recommends'][0]['min_version']);
        $this->assertNotEmpty($j['recommends'][0]['reason']);
    }
}
