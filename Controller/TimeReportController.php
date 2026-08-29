<?php

namespace Kanboard\Plugin\TimeReport\Controller;

use Kanboard\Controller\BaseController;
use Kanboard\Core\Controller\AccessForbiddenException;
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
    private const GRANULARITIES = ['day', 'week', 'task', 'total', 'user'];

    /** The report form: project picker, range inputs, toggles. */
    public function index(): void
    {
        $userId = $this->userSession->getId();
        $projects = $this->accessibleProjects($userId);

        $values = [
            'start_date'  => date('Y-m-01'),
            'end_date'    => date('Y-m-d'),
            'granularity' => 'day',
        ];
        $selected = $this->prefillProjectId($this->request->getIntegerParam('project_id'), $projects);
        if ($selected > 0) {
            $values['project_id'] = $selected;
        }

        $this->response->html($this->helper->layout->app('TimeReport:report/form', [
            'title'             => t('Time Report'),
            'projects'          => $projects,
            'ai_enabled'        => $this->isAiEnabled(),
            'profiles'          => $this->aiProfiles(),
            'values'            => $values,
            'can_report_others' => $this->canReportOnAnyProject($userId),
            'send_descriptions' => $this->timeReportModel->sendDescriptionsEnabled(),
        ]));
    }

    /** Validate + access-guard + compute + render the report (and the Markdown payload). */
    public function generate(): void
    {
        $this->checkCSRFForm();
        $report = $this->buildReportFromRequest();
        $this->renderReport($report);
    }

    /**
     * Render the report screen with the inline control bar's data (Option B).
     *
     * Carries the accessible projects, AI profiles and permission flag so the
     * collapse-to-summary "Edit filters" panel can re-submit to generate() in place.
     */
    protected function renderReport(array $report): void
    {
        $userId = $this->userSession->getId();
        $this->response->html($this->helper->layout->app('TimeReport:report/show', [
            'title'             => t('Time Report'),
            'report'            => $report,
            'markdown'          => $this->helper->timeReport->toMarkdown($report),
            'ai_enabled'        => $this->isAiEnabled(),
            'projects'          => $this->accessibleProjects($userId),
            'profiles'          => $this->aiProfiles(),
            'can_report_others' => $this->canReportOnAnyProject($userId),
        ]));
    }

    /** Accessible projects as id => name, for the report form and the inline control bar. */
    protected function accessibleProjects(int $userId): array
    {
        $projects = [];
        foreach ($this->projectPermissionModel->getActiveProjectIds($userId) as $pid) {
            $p = $this->projectModel->getById((int) $pid);
            if (! empty($p)) {
                $projects[(int) $pid] = $p['name'];
            }
        }
        return $projects;
    }

    /** True when the user may report on others in at least one accessible project. */
    protected function canReportOnAnyProject(int $userId): bool
    {
        if ($this->userSession->isAdmin()) {
            return true;
        }
        foreach (array_keys($this->accessibleProjects($userId)) as $pid) {
            if ($this->timeReportModel->canReportOnOthers((int) $pid, $userId)) {
                return true;
            }
        }
        return false;
    }

    /** One-click report for a project: read-only GET, fixed quick defaults. No CSRF (no state change). */
    public function view(): void
    {
        $userId    = $this->userSession->getId();
        $projectId = $this->request->getIntegerParam('project_id');

        try {
            $report = $this->quickReport($projectId, $userId);
        } catch (AccessForbiddenException $e) {
            $this->response->redirect($this->helper->url->to('TimeReportController', 'index', ['plugin' => 'TimeReport']));
            return;
        }

        $this->renderReport($report);
    }

    /** Fixed quick defaults: this month to date, per task, no detail, no AI. Access-guarded by the model. */
    protected function quickReport(int $projectId, int $userId): array
    {
        // report() already returns include_detail => false for the false argument above.
        return $this->timeReportModel->report($projectId, date('Y-m-01'), date('Y-m-d'), 'task', false, $userId);
    }

    /** The project id to pre-select in the form, or 0 when the requested id isn't in the user's accessible list. */
    protected function prefillProjectId(int $requested, array $projects): int
    {
        return ($requested > 0 && isset($projects[$requested])) ? $requested : 0;
    }

    /** Same params, streamed as a CSV download. */
    public function exportCsv(): void
    {
        $this->checkCSRFForm();
        $report = $this->buildReportFromRequest();

        // A CSV carries no on-screen notice, so a silently narrowed export would look
        // like a valid team invoice covering people it does not contain. Refuse it.
        if (! empty($report['scope_denied'])) {
            $this->response->redirect($this->helper->url->to('TimeReportController', 'index', ['plugin' => 'TimeReport']));
            return;
        }

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

    /**
     * Per-row AI summary (POST, CSRF, JSON). Lazy, cache-backed, content-hashed.
     *
     * Rebuilds the report context (reusing report() — no new mining path), locates the
     * requested row, and runs the fresh/stale/missing/force state machine (§6.5).
     * Guarded by login (router), CSRF, the AI gate, and assertProjectAccess (inside
     * report()). Stale rows are served from cache WITHOUT spending — regeneration only
     * happens on an explicit force.
     */
    public function rowSummary(): void
    {
        $this->checkCSRFForm();

        if (! $this->isAiEnabled()) {
            throw new AccessForbiddenException(t('AI summaries are not available.'));
        }

        $this->response->json($this->computeRowSummary($this->request->getValues()));
    }

    /** The rowSummary state machine, isolated from the HTTP shell for testing. */
    protected function computeRowSummary(array $values): array
    {
        $userId      = $this->userSession->getId();
        $projectId   = (int) ($values['project_id'] ?? 0);
        $granularity = in_array($values['granularity'] ?? '', ['task', 'day', 'week'], true) ? $values['granularity'] : '';
        $rowKey      = (string) ($values['row_key'] ?? '');
        $force       = ! empty($values['force']);

        if ($granularity === '' || $rowKey === '') {
            return ['error' => t('Invalid summary request.')];
        }

        $startDate = $this->validDate($values['start_date'] ?? '', date('Y-m-01'));
        $endDate   = $this->validDate($values['end_date'] ?? '', date('Y-m-d'));
        [$subjectUserIds, $allUsers] = $this->subjectSelection($values);

        // Access is enforced inside report(); detail on so subtasks/descriptions are present.
        $report = $this->timeReportModel->report($projectId, $startDate, $endDate, $granularity, true, $userId, $subjectUserIds, $allUsers);

        $row = null;
        foreach ($report['breakdown'] as $b) {
            if ((string) $b['key'] === $rowKey) {
                $row = $b;
                break;
            }
        }
        if ($row === null) {
            return ['error' => t('This row is not part of the current report.')];
        }

        $profileId          = $this->validProfileId($values['profile_id'] ?? null);
        $includeDescriptions = $this->timeReportModel->sendDescriptionsEnabled();
        $memberTaskIds       = array_map('intval', $row['task_ids'] ?? []);
        $contentRows         = $this->timeReportModel->buildTaskContentRows($projectId, $memberTaskIds, $includeDescriptions);

        // Resolve every member task's summary (cache-first), collecting current hashes.
        $memberSummaries = [];
        $memberHashes    = [];
        $anyStale        = false;
        foreach ($memberTaskIds as $taskId) {
            $contentRow = $contentRows[$taskId] ?? null;
            if ($contentRow === null) {
                continue;
            }
            [$summary, $hash, $stale] = $this->resolveTaskSummary($contentRow, $includeDescriptions, $force, $profileId);
            $memberHashes[$taskId]    = $hash;
            $memberSummaries[]        = ['title' => $contentRow['title'], 'summary' => $summary['summary'], 'highlights' => $summary['highlights']];
            $anyStale = $anyStale || $stale;
        }

        if ($granularity === 'task') {
            $only = $memberSummaries[0] ?? ['summary' => '', 'highlights' => []];
            return [
                'summary'    => $only['summary'],
                'highlights' => $only['highlights'],
                'stale'      => $anyStale && ! $force,
            ];
        }

        return $this->resolveAggregateSummary(
            $projectId,
            $granularity,
            $rowKey,
            (string) $row['label'],
            $memberSummaries,
            $memberHashes,
            $anyStale,
            $force,
            $profileId
        );
    }

    /**
     * One member task's summary via the cache state machine.
     *
     * @return array{0: array{summary:string,highlights:array}, 1: string, 2: bool} [summary, currentHash, stale]
     */
    protected function resolveTaskSummary(array $contentRow, bool $includeDescriptions, bool $force, ?string $profileId): array
    {
        $hash   = \Kanboard\Plugin\TimeReport\Model\TimeReportModel::taskContentHash($contentRow, $includeDescriptions);
        $cache  = $this->aiSummaryCache;
        $cached = $cache->getTask((int) $contentRow['task_id']);
        $state  = \Kanboard\Plugin\TimeReport\Model\AiSummaryCache::classify($cached, $hash);

        if ($force || $state === 'missing') {
            $generated = $this->aiSummaryModel->summarizeTask($contentRow, $profileId);
            $cache->saveTask((int) $contentRow['task_id'], $hash, $generated['summary'], $generated['highlights']);
            return [$generated, $hash, false];
        }

        // fresh or stale: serve cache with no spend; stale is flagged, not regenerated.
        return [
            ['summary' => (string) $cached['summary'], 'highlights' => $cached['highlights']],
            $hash,
            $state === 'stale',
        ];
    }

    /**
     * The aggregate (day/week) row via its own cache state machine, composed from the
     * already-resolved member summaries. Any member staleness ⇒ aggregate hash mismatch.
     */
    protected function resolveAggregateSummary(int $projectId, string $granularity, string $rowKey, string $rowLabel, array $memberSummaries, array $memberHashes, bool $anyStale, bool $force, ?string $profileId): array
    {
        $aggHash = \Kanboard\Plugin\TimeReport\Model\TimeReportModel::aggregateContentHash($memberHashes);
        $cache   = $this->aiSummaryCache;
        $cached  = $cache->getAggregate($projectId, $granularity, $rowKey);
        $state   = \Kanboard\Plugin\TimeReport\Model\AiSummaryCache::classify($cached, $aggHash);

        if ($force || $state === 'missing') {
            $generated = $this->aiSummaryModel->summarizeAggregate($granularity, $rowLabel, $memberSummaries, $profileId);
            $cache->saveAggregate($projectId, $granularity, $rowKey, $aggHash, $generated['summary'], $generated['highlights']);
            return ['summary' => $generated['summary'], 'highlights' => $generated['highlights'], 'stale' => false];
        }

        // fresh or stale: serve cache without spending.
        return [
            'summary'    => (string) $cached['summary'],
            'highlights' => $cached['highlights'],
            'stale'      => $state === 'stale',
        ];
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

        [$subjectUserIds, $allUsers] = $this->subjectSelection($values);

        // Mine ONCE. Compute the detail set when the user asked to display it OR the AI
        // summary needs it, so the AI branch never re-runs report() a second time.
        // Model access-guards the project (assertProjectAccess) before any mining.
        $needDetail = $includeDetail || $wantsAi;
        $report = $this->timeReportModel->report($projectId, $startDate, $endDate, $granularity, $needDetail, $userId, $subjectUserIds, $allUsers);

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

    /**
     * What the request asked for: an explicit set, or the "all users" intent.
     *
     * scope=all is deliberately NOT resolved into ids here. The model needs the intact
     * intent to tell "asked for the team and may not have it" (which must warn) apart
     * from "asked only for myself" (which must not). Resolving it here would collapse
     * the two for any user without permission on the chosen project.
     *
     * @return array{0: ?array, 1: bool} [explicit ids or null, all-users intent]
     */
    protected function subjectSelection(array $values): array
    {
        if (! empty($values['user_ids']) && is_array($values['user_ids'])) {
            return [array_map('intval', $values['user_ids']), false];
        }

        return [null, ($values['scope'] ?? '') === 'all'];
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

    /**
     * Normalize a submitted date to ISO YYYY-MM-DD, or fall back.
     *
     * Accepts an ISO date directly and, so the native datepicker (form->date, which
     * posts in the user's configured date format) round-trips correctly, parses any
     * user-format date via the DateParser. Unparseable input falls back.
     */
    private function validDate(string $value, string $fallback): string
    {
        $value = trim($value);
        if ($value === '') {
            return $fallback;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            return $value;
        }
        $ts = (int) $this->dateParser->getTimestamp($value);
        return $ts > 0 ? date('Y-m-d', $ts) : $fallback;
    }
}
