<?php

namespace Kanboard\Plugin\TimeReport\Controller;

use Kanboard\Controller\BaseController;
use Kanboard\Plugin\TimeReport\Model\AiGate;

/**
 * TimeReportController — the report form + the three delivery surfaces.
 *
 * Every action operates on the current user's OWN data (userSession id; login
 * enforced by the router). Access to the chosen project is enforced by
 * TimeReportModel::assertProjectAccess() before any mining. The AI narrative is
 * gated by AiGate and ignored (never errored) when the gate is closed.
 */
class TimeReportController extends BaseController
{
    private const GRANULARITIES = ['day', 'week', 'task', 'total'];

    /** The report form: project picker, range inputs, toggles. */
    public function index(): void
    {
        $userId = $this->userSession->getId();
        $projectIds = $this->projectPermissionModel->getActiveProjectIds($userId);
        $projects = [];
        foreach ($projectIds as $pid) {
            $p = $this->projectModel->getById((int) $pid);
            if (! empty($p)) {
                $projects[(int) $pid] = $p['name'];
            }
        }

        $this->response->html($this->helper->layout->app('TimeReport:report/form', [
            'title'      => t('Time Report'),
            'projects'   => $projects,
            'ai_enabled' => $this->isAiEnabled(),
            'profiles'   => $this->aiProfiles(),
            'values'     => [
                'start_date'  => date('Y-m-01'),
                'end_date'    => date('Y-m-d'),
                'granularity' => 'day',
            ],
        ]));
    }

    /** Validate + access-guard + compute + render the report (and the Markdown payload). */
    public function generate(): void
    {
        $this->checkCSRFForm();
        $report = $this->buildReportFromRequest();

        $markdown = $this->helper->timeReport->toMarkdown($report);

        $this->response->html($this->helper->layout->app('TimeReport:report/show', [
            'title'      => t('Time Report'),
            'report'     => $report,
            'markdown'   => $markdown,
            'ai_enabled' => $this->isAiEnabled(),
        ]));
    }

    /** Same params, streamed as a CSV download. */
    public function exportCsv(): void
    {
        $this->checkCSRFForm();
        $report = $this->buildReportFromRequest();

        $helper = $this->helper->timeReport;
        $csv = $helper->toCsv($report);
        $filename = $helper->csvFilename($report['project_name'], $report['start_date'], $report['end_date']);

        // NOTE: adapted from the brief's send()-then-echo to withBody()-then-send()
        // — Response::send() only emits $this->httpBody (set via withBody()); a
        // bare echo() after send() would rely on output-buffering side effects
        // outside the Response class's own contract, which is fragile under test
        // and not guaranteed across SAPIs. withoutCache() matches core's own CSV
        // export behavior in Response::csv()/ExportController.
        $this->response->withoutCache();
        $this->response->withContentType('text/csv; charset=utf-8');
        $this->response->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $this->response->withBody($csv);
        $this->response->send();
    }

    /** Shared: read/validate params, compute the report, attach AI only when the gate is open. */
    protected function buildReportFromRequest(): array
    {
        $userId      = $this->userSession->getId();
        $values      = $this->request->getValues();
        $projectId   = (int) ($values['project_id'] ?? 0);
        $startDate   = $this->validDate($values['start_date'] ?? '', date('Y-m-01'));
        $endDate     = $this->validDate($values['end_date'] ?? '', date('Y-m-d'));
        $granularity = in_array($values['granularity'] ?? '', self::GRANULARITIES, true) ? $values['granularity'] : 'day';
        $includeDetail = ! empty($values['include_detail']);
        $wantsAi       = ! empty($values['include_ai_summary']) && $this->isAiEnabled();

        // Mine ONCE. Compute the detail set when the user asked to display it OR the AI
        // summary needs it, so the AI branch never re-runs report() a second time.
        // Model access-guards the project (assertProjectAccess) before any mining.
        $needDetail = $includeDetail || $wantsAi;
        $report = $this->timeReportModel->report($projectId, $startDate, $endDate, $granularity, $needDetail, $userId);

        if ($wantsAi) {
            $profileId = $this->validProfileId($values['profile_id'] ?? null);
            try {
                $report['ai'] = $this->aiSummaryModel->summarize($report['detail'], $profileId);
            } catch (\Throwable $e) {
                $report['ai'] = ['summary' => '', 'highlights' => [], 'error' => t('The AI summary could not be generated.')];
            }
        }

        // Detail is displayed/exported only per the user's explicit choice, even when it
        // was computed solely to feed the AI summary above.
        $report['include_detail'] = $includeDetail;

        return $report;
    }

    /** AiGate delegate — overridable in tests. */
    protected function isAiEnabled(): bool
    {
        return AiGate::isReady($this->container);
    }

    private function aiProfiles(): array
    {
        if (! $this->isAiEnabled()) {
            return [];
        }
        $registry = new \Kanboard\Plugin\AiConnector\Model\ProviderRegistry($this->container);
        return $registry->listProfiles();
    }

    /** Validate a submitted profile id against the registry's list; null → default. */
    private function validProfileId(?string $submitted): ?string
    {
        if ($submitted === null || $submitted === '') {
            return null;
        }
        $registry = new \Kanboard\Plugin\AiConnector\Model\ProviderRegistry($this->container);
        $ids = array_column($registry->listProfiles(), 'id');
        return in_array($submitted, $ids, true) ? $submitted : null;
    }

    private function validDate(string $value, string $fallback): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : $fallback;
    }
}
