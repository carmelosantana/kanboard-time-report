<?php

namespace Kanboard\Plugin\TimeReport;

use Kanboard\Core\Plugin\Base;
use Kanboard\Plugin\TimeReport\Model\AiGate;
use Kanboard\Plugin\TimeReport\Model\AiSummaryModel;
use Kanboard\Plugin\TimeReport\Model\TimeReportModel;
use Kanboard\Plugin\TimeReport\Helper\TimeReportHelper;

/**
 * TimeReport — self-only consultant hours report for one project + date range.
 *
 * Pure query→render: no persisted state, no DB migration. AI narrative summary
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

        // ── Template helper: $this->helper->timeReport()->formatHours(...) ─────
        $this->helper->register('timeReport', TimeReportHelper::class);

        // ── AI availability gate (single source of truth) ─────────────────────
        $this->aiEnabled = AiGate::isReady($this->container);

        // ── Routes ────────────────────────────────────────────────────────────
        $this->route->addRoute('timereport', 'TimeReportController', 'index', 'TimeReport');
        $this->route->addRoute('timereport/generate', 'TimeReportController', 'generate', 'TimeReport');
        $this->route->addRoute('timereport/export-csv', 'TimeReportController', 'exportCsv', 'TimeReport');

        // ── Entry-point link in the header user dropdown ──────────────────────
        $this->template->hook->attach('template:header:dropdown', 'TimeReport:report/header_dropdown');

        // ── Assets (CSP-safe: external files, delegated JS) ───────────────────
        $this->hook->on('template:layout:css', ['template' => 'plugins/TimeReport/Assets/css/timereport.css']);
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
        return '1.0.0';
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
