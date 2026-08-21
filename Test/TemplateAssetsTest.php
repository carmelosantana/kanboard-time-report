<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;

class TemplateAssetsTest extends Base
{
    private function tpl(string $rel): string
    {
        return file_get_contents(dirname(__DIR__) . '/Template/report/' . $rel);
    }

    public function testNoInlineScriptOrHandlersInTemplates(): void
    {
        foreach (['form.php', 'show.php', '_breakdown.php', '_detail.php', 'header_dropdown.php'] as $f) {
            $src = $this->tpl($f);
            $this->assertStringNotContainsString('<script', $src, "$f must not contain inline <script> (CSP)");
            $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=\s*["\']/i', $src, "$f must not contain inline on* handlers (CSP)");
        }
    }

    public function testShowEmitsMarkdownPayloadAndCopyButton(): void
    {
        $src = $this->tpl('show.php');
        $this->assertStringContainsString('tr-markdown', $src, 'show must emit the Markdown payload container');
        $this->assertStringContainsString('data-tr-copy', $src, 'show must have the delegated copy button');
    }

    public function testJsUsesClipboardAndDelegation(): void
    {
        $js = file_get_contents(dirname(__DIR__) . '/Assets/js/timereport.js');
        $this->assertStringContainsString('navigator.clipboard', $js);
        $this->assertStringContainsString('data-tr-copy', $js);
    }
}
