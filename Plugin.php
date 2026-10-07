<?php

namespace Kanboard\Plugin\TimeReport;

use Kanboard\Core\Plugin\Base;
use Kanboard\Plugin\TimeReport\Api\TimeReportProgressProcedure;
use Kanboard\Plugin\TimeReport\Api\TimeReportXpProcedure;
use Kanboard\Plugin\TimeReport\Model\AiGate;
use Kanboard\Plugin\TimeReport\Model\AiSummaryModel;
use Kanboard\Plugin\TimeReport\Model\AiSummaryCache;
use Kanboard\Plugin\TimeReport\Model\ProgressModel;
use Kanboard\Plugin\TimeReport\Model\SubtaskCompletionStamp;
use Kanboard\Plugin\TimeReport\Model\XpCache;
use Kanboard\Plugin\TimeReport\Model\XpModel;
use Kanboard\Plugin\TimeReport\Subscriber\ProgressSubscriber;
use Kanboard\Plugin\TimeReport\Model\TimeReportModel;
use Kanboard\Plugin\TimeReport\Helper\TimeReportHelper;
use Kanboard\Plugin\TimeReport\Helper\ProgressHelper;

/**
 * TimeReport — self-only consultant hours report for one project + date range.
 *
 * Pure query→render over the time data itself: the report persists nothing. The
 * optional AI narrative summary is cached in the plugin's own tables (Schema/),
 * is optional (AiConnector) and degrades to fully manual when absent.
 */
class Plugin extends Base
{
    private bool $aiEnabled = false;

    public function initialize(): void
    {
        // ── Model services (lazy singletons) ──────────────────────────────────
        $this->container['timeReportModel'] = function ($c) {
            return new TimeReportModel($c);
        };
        $this->container['aiSummaryModel'] = function ($c) {
            return new AiSummaryModel($c);
        };
        $this->container['aiSummaryCache'] = function ($c) {
            return new AiSummaryCache($c);
        };
        $this->container['progressModel'] = fn ($c) => new ProgressModel($c);
        $this->container['xpModel'] = fn ($c) => new XpModel($c);
        $this->container['xpCache'] = fn ($c) => new XpCache($c);
        $this->container['subtaskCompletionStamp'] = fn ($c) => new SubtaskCompletionStamp($c);

        // ── Progress/XP bookkeeping: completion stamps + cache invalidation ───
        $this->dispatcher->addSubscriber(new ProgressSubscriber($this->container));

        // ── JSON-RPC (read-only). withObject so core wins any name clash. ─────
        $this->api->getProcedureHandler()->withObject(new TimeReportProgressProcedure($this->container));
        $this->api->getProcedureHandler()->withObject(new TimeReportXpProcedure($this->container));

        // ── Template helper: $this->helper->timeReport->formatHours(...) ──────
        // (property access — Kanboard's Helper exposes registered helpers via __get, not __call)
        $this->helper->register('timeReport', TimeReportHelper::class);
        $this->helper->register('timeReportProgress', ProgressHelper::class);

        // ── AI availability gate (single source of truth) ─────────────────────
        $this->aiEnabled = AiGate::isReady($this->container);

        // ── Routes ────────────────────────────────────────────────────────────
        $this->route->addRoute('timereport', 'TimeReportController', 'index', 'TimeReport');
        $this->route->addRoute('timereport/generate', 'TimeReportController', 'generate', 'TimeReport');
        $this->route->addRoute('timereport/export-csv', 'TimeReportController', 'exportCsv', 'TimeReport');
        $this->route->addRoute('timereport/view', 'TimeReportController', 'view', 'TimeReport');
        $this->route->addRoute('timereport/row-summary', 'TimeReportController', 'rowSummary', 'TimeReport');
        $this->route->addRoute('timereport/tpb-dismiss', 'TimeReportController', 'dismissTpbNotice', 'TimeReport');

        // ── Entry-point link in the header user dropdown ──────────────────────
        $this->template->hook->attach('template:header:dropdown', 'TimeReport:report/header_dropdown');

        // ── Entry links in the project ≡ menu (core passes $project) ──────────
        $this->template->hook->attach('template:project:dropdown', 'TimeReport:project/menu');

        // ── Admin opt-in on Settings → Integrations (off by default) ──────────
        // Governs whether task descriptions are gathered and forwarded to the AI
        // provider. Persisted via core ConfigController::save (redirect=integrations).
        $this->template->hook->attach('template:config:integrations', 'TimeReport:config/integrations');

        // ── Progress on board cards: a hook, never a board/task_footer override ──
        $this->template->hook->attach('template:board:task:footer', 'TimeReport:board/progress');

        // ── Theme-only surfaces (hidden by progress.css; a theme reveals them) ──
        $this->template->hook->attach('template:project:header:after', 'TimeReport:project/track');
        $this->template->hook->attach('template:layout:top', 'TimeReport:layout/level');

        // ── Admin notice while TaskProgressBar is loaded (spec Kanboard #5382; visible, not theme-gated) ──
        $this->template->hook->attach('template:layout:top', 'TimeReport:config/tpb_notice');

        // ── Assets (CSP-safe: external files, delegated JS) ───────────────────
        $this->hook->on('template:layout:css', ['template' => 'plugins/TimeReport/Assets/css/timereport.css']);
        $this->hook->on('template:layout:css', ['template' => 'plugins/TimeReport/Assets/css/progress.css']);
        $this->hook->on('template:layout:js', ['template' => 'plugins/TimeReport/Assets/js/timereport.js']);
    }

    /** True when the PHP runtime satisfies the >= 8.4 gate. $versionId override for tests. */
    public function isPhpCompatible(?int $versionId = null): bool
    {
        return ($versionId ?? PHP_VERSION_ID) >= 80400;
    }

    public function isAiEnabled(): bool
    {
        return $this->aiEnabled;
    }

    public function getPluginName(): string
    {
        return 'TimeReport';
    }

    public function getPluginDescription(): string
    {
        return t('Consultant hours reporting: pick a project and date range, choose per-day/per-week/per-task breakdowns, list completed tasks, and optionally add an AI summary. Copy as Markdown or export CSV.');
    }

    public function getPluginAuthor(): string
    {
        return 'Carmelo Santana';
    }

    public function getPluginVersion(): string
    {
        return '1.4.4';
    }

    public function getPluginHomepage(): string
    {
        return 'https://github.com/carmelosantana/kanboard-time-report';
    }

    public function getCompatibleVersion(): string
    {
        return '>=1.2.47';
    }
}
