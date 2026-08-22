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
        foreach (['form.php', 'show.php', '_breakdown.php', '_detail.php', 'header_dropdown.php', '_untracked.php'] as $f) {
            $src = $this->tpl($f);
            $this->assertStringNotContainsString('<script', $src, "$f must not contain inline <script> (CSP)");
            $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=\s*["\']/i', $src, "$f must not contain inline on* handlers (CSP)");
        }
    }

    public function testHoursCellsAreClickToCopy(): void
    {
        foreach (['_breakdown.php', '_detail.php', 'show.php'] as $f) {
            $src = $this->tpl($f);
            $this->assertStringContainsString('data-tr-copyval', $src, "$f hours value must be click-to-copy");
            $this->assertStringContainsString('tr-copy-num', $src, "$f must mark the copyable cell");
        }
    }

    public function testBreakdownAndDetailDecorateDatesWithWeekday(): void
    {
        $this->assertStringContainsString('withWeekday', $this->tpl('_breakdown.php'));
        $this->assertStringContainsString('withWeekday', $this->tpl('_detail.php'));
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

    public function testJsHandlesSingleValueCopyAndKeyboard(): void
    {
        $js = file_get_contents(dirname(__DIR__) . '/Assets/js/timereport.js');
        $this->assertStringContainsString('data-tr-copyval', $js, 'JS must copy single values');
        $this->assertStringContainsString('keydown', $js, 'JS must support Enter/Space copy');
        $this->assertStringContainsString('tr-copy-badge', $js, 'JS must show the Copied badge');
    }

    public function testCopyLabelIsLocalizableAndBadgeDedupes(): void
    {
        foreach (['_breakdown.php', '_detail.php', 'show.php'] as $f) {
            $this->assertStringContainsString('data-tr-copied', $this->tpl($f), "$f copy target must carry a localizable copied label");
        }
        $js = file_get_contents(dirname(__DIR__) . '/Assets/js/timereport.js');
        $this->assertStringContainsString('data-tr-copied', $js);
        $this->assertStringContainsString('querySelector(".tr-copy-badge")', $js, 'badge must be deduped before append');
    }

    public function testUntrackedPartialIsGatedAndWired(): void
    {
        $partial = $this->tpl('_untracked.php');
        $this->assertStringContainsString('tr-untracked', $partial, 'partial must carry its container class');
        $this->assertStringContainsString("untracked", $partial);
        $this->assertStringContainsString("task_count", $partial, 'banner block must be gated on untracked.task_count');

        $show = $this->tpl('show.php');
        $this->assertStringContainsString('TimeReport:report/_untracked', $show, 'show must render the untracked partial');
        $this->assertStringContainsString("task_count", $show, 'show must gate the partial on untracked.task_count');
    }
}
