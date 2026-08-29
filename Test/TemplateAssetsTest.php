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
        foreach (['form.php', 'show.php', '_breakdown.php', '_detail.php', 'header_dropdown.php', '_untracked.php', '_users.php'] as $f) {
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

    public function testProjectMenuPartialLinksBothEntries(): void
    {
        $src = file_get_contents(dirname(__DIR__) . '/Template/project/menu.php');
        $this->assertStringNotContainsString('<script', $src, 'menu partial must not contain inline <script> (CSP)');
        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=\s*["\']/i', $src, 'menu partial must not contain inline on* handlers (CSP)');
        $this->assertStringContainsString("'view'", $src, 'menu must link the quick view action');
        $this->assertStringContainsString("'index'", $src, 'menu must link the report form');
        $this->assertStringContainsString("project['id']", $src, 'menu links must carry the project id');
    }

    public function testPluginWiresProjectMenuAndViewRoute(): void
    {
        $plugin = file_get_contents(dirname(__DIR__) . '/Plugin.php');
        $this->assertStringContainsString("addRoute('timereport/view'", $plugin, 'view route must be registered');
        $this->assertStringContainsString('template:project:dropdown', $plugin, 'project ≡ menu hook must be attached');
        $this->assertStringContainsString('TimeReport:project/menu', $plugin, 'the menu partial must be attached to the hook');
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

    public function testUsersPanelExistsAndPostsUserIds(): void
    {
        $src = $this->tpl('_users.php');
        $this->assertStringContainsString('user_ids[]', $src);
        $this->assertStringContainsString('csrf', $src, 'the refine panel is a POST and must carry CSRF');
        $this->assertStringContainsString("'generate'", $src);
    }

    public function testCsvExportCarriesTheSubjectSet(): void
    {
        $src = $this->tpl('show.php');
        $this->assertStringContainsString('subject_user_ids', $src, 'CSV must export the same people shown on screen');
        $this->assertStringContainsString('scope_denied', $src, 'the denial notice must be rendered');
    }

    /**
     * A denied result posts back [self], which would look like an ordinary self-only
     * request and slip past exportCsv()'s guard. The export action must not be
     * rendered at all in that state.
     */
    public function testDeniedResultRendersNoCsvExportAction(): void
    {
        $src = $this->tpl('show.php');
        $this->assertMatchesRegularExpression(
            '/empty\(\$report\[.scope_denied.\]\).*?exportCsv/s',
            $src,
            'the CSV export form must be suppressed when the scope was denied'
        );
    }

    public function testFormHasScopeToggleAndUserGranularity(): void
    {
        $src = $this->tpl('form.php');
        $this->assertStringContainsString("'scope'", $src);
        $this->assertStringContainsString('can_report_others', $src, 'the toggle is gated on permission');
        $this->assertStringContainsString("'user' => t('By user')", $src);
    }

    public function testDetailAssigneeColumnIsConditional(): void
    {
        $src = $this->tpl('_detail.php');
        $this->assertStringContainsString('multi_user', $src);
        $this->assertStringContainsString('Assignee', $src);
    }

    // ── Task 8: expandable per-row summaries ───────────────────────────────────

    public function testBreakdownRendersExpanderAndSummaryContextWhenAiEnabled(): void
    {
        $src = $this->tpl('_breakdown.php');
        // Gated on ai_enabled + the task/day/week granularities only.
        $this->assertStringContainsString('ai_enabled', $src);
        $this->assertMatchesRegularExpression("/'task',\s*'day',\s*'week'/", $src, 'per-row summaries limited to task/day/week');
        $this->assertStringContainsString('data-tr-row-toggle', $src, 'each summarizable row has an expander');
        $this->assertStringContainsString('data-row-key', $src);
        $this->assertStringContainsString('tr-summary-row', $src, 'a lazily-populated detail row follows each row');
        $this->assertStringContainsString('id="tr-summary-context"', $src, 'the AJAX context form must exist');
        $this->assertStringContainsString('rowSummary', $src, 'context must point at the row-summary endpoint');
        $this->assertStringContainsString('csrf', $src, 'the POST context must carry CSRF');
    }

    public function testShowPassesAiEnabledToBreakdown(): void
    {
        $src = $this->tpl('show.php');
        $this->assertMatchesRegularExpression("/_breakdown.*ai_enabled/s", $src);
    }

    public function testSummaryJsIsDelegatedAndFetchesEndpoint(): void
    {
        $js = file_get_contents(dirname(__DIR__) . '/Assets/js/timereport.js');
        $this->assertStringContainsString('data-tr-row-toggle', $js, 'JS must delegate the expander toggle');
        $this->assertStringContainsString('data-tr-regenerate', $js, 'JS must handle regenerate');
        $this->assertStringContainsString('fetch(', $js, 'JS must POST to the endpoint');
        $this->assertStringContainsString('tr-stale-badge', $js, 'JS must render the may-be-outdated badge when stale');
        $this->assertStringContainsString('TimeReportSummaries', $js, 'bulk fill hook must be exposed');
        // XSS-safety: model text set via textContent, never innerHTML of untrusted data.
        $this->assertStringContainsString('textContent', $js);
    }
}
