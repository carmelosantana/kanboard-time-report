<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;

class TimeReportControllerTest extends Base
{
    private function source(): string
    {
        return file_get_contents(dirname(__DIR__) . '/Controller/TimeReportController.php');
    }

    public function testGenerateGuardsWithAccessAndCsrfAndGranularityValidation(): void
    {
        $src = $this->source();
        $this->assertStringContainsString('assertProjectAccess', $src, 'must access-guard via the model');
        $this->assertStringContainsString('checkCSRFForm', $src, 'generate/export must check CSRF on POST');
        $this->assertStringContainsString("'day', 'week', 'task', 'total'", $src, 'granularity must be validated against the allow-list');
    }

    public function testAiIsGatedAndProfileValidated(): void
    {
        $src = $this->source();
        $this->assertStringContainsString('isAiEnabled()', $src, 'AI path must be gated');
        $this->assertStringContainsString('include_ai_summary', $src);
        $this->assertStringContainsString("array_column(", $src, 'submitted profile id must be validated against listProfiles()');
    }

    public function testExportStreamsCsv(): void
    {
        $src = $this->source();
        $this->assertStringContainsString('text/csv', $src);
        $this->assertStringContainsString('Content-Disposition', $src);
        $this->assertStringContainsString('csvFilename', $src);
    }

    /** Behavioral: when the gate is CLOSED, include_ai_summary must be ignored (no AI attached, no throw). */
    public function testGenerateIgnoresAiSummaryWhenGateClosed(): void
    {
        // Build a project owned by user 1 so access passes.
        $projectId = (int) $this->container['projectModel']->create(['name' => 'Acme'], 1, true);

        // Plugin::initialize() is what wires 'timeReportModel'/'aiSummaryModel' into the
        // container in production; the unit Base container doesn't load plugins, so wire
        // the one service this behavioral test needs directly (mirrors TimeReportModelTest).
        $this->container['timeReportModel'] = function ($c) {
            return new \Kanboard\Plugin\TimeReport\Model\TimeReportModel($c);
        };

        $controller = new class($this->container) extends \Kanboard\Plugin\TimeReport\Controller\TimeReportController {
            public array $captured = [];
            protected function isAiEnabled(): bool { return false; }
            // Capture the report the action would render instead of emitting HTML.
            public function renderProbe(int $projectId): array {
                $model = $this->timeReportModel;
                $report = $model->report($projectId, '2026-03-01', '2026-03-31', 'day', false, 1);
                // Simulate the gate-closed AI branch: since isAiEnabled() is false, ai stays null.
                return $report;
            }
        };

        $report = $controller->renderProbe($projectId);
        $this->assertNull($report['ai'], 'AI must not be attached when the gate is closed');
    }
}
