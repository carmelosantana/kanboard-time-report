<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;

class PluginMetaTest extends Base
{
    private function json(): array
    {
        return json_decode(file_get_contents(dirname(__DIR__) . '/plugin.json'), true);
    }

    public function testVersionIsExactly141(): void
    {
        $this->assertSame('1.4.2', $this->json()['version']);
    }

    /** tag == version across the three files the CI checks (plugin.json, Plugin.php, CHANGELOG). */
    public function testVersionAgreesAcrossFiles(): void
    {
        $version = $this->json()['version'];

        $plugin = file_get_contents(dirname(__DIR__) . '/Plugin.php');
        $this->assertMatchesRegularExpression(
            "/getPluginVersion\(\)[^}]*return '" . preg_quote($version, '/') . "'/s",
            $plugin,
            'Plugin.php getPluginVersion() must equal plugin.json version'
        );

        $changelog = file_get_contents(dirname(__DIR__) . '/CHANGELOG.md');
        $this->assertStringContainsString('## ' . $version . ' — ', $changelog, 'CHANGELOG must carry the released version entry');
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
