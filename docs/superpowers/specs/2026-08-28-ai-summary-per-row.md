# TimeReport — Per-row AI summaries, richer AI context, inline controls

**Status:** Spec ready for build · **Target version:** 1.4.0 · **Date:** 2026-08-28
**Deliverable of:** a `/wayfinder` planning session (grilling + domain-modeling + prototype)
**Build via:** `/sdd` — fresh opus implementer + reviewer, TDD, one task at a time.

---

## 1. Problem

The AI narrative summary produces poor results and is structurally limited:

1. **The AI never sees the work.** [`AiSummaryModel::buildMessages()`](../../../Model/AiSummaryModel.php) forwards only task-level fields (`title`, `hours`, `category`, `tags`, `date_completed`). [`TimeReportModel::buildDetail()`](../../../Model/TimeReportModel.php) never gathers subtasks. So the model narrates "3.5h on #42 'Onboarding'" with no idea what was actually done. **This is the biggest quality lever** — bigger than model or prompt.
2. **One flat blob.** A single `{summary, highlights}` is rendered once at the bottom of the report ([`show.php`](../../../Template/report/show.php)). Nothing is per-row or inline.
3. **No filters on the results screen.** Changing breakdown/scope/range means going back to the form and regenerating from scratch.
4. **Plain date inputs.** No datepicker.

## 2. Goals

- Feed the AI the **actual work**: completed subtasks (title + hours), and — behind an admin opt-in — task and subtask **descriptions**.
- **Per-row inline summaries** for the `day`, `week`, and `task` breakdowns, so you can understand all work completed on a given day or task, and paste any single one.
- Summaries load **lazily on demand**, are **cached** so they survive across different reports, and carry a **"may be outdated"** badge when the underlying content has changed — with one-click **regenerate**.
- The previous screen's **filter controls, carried inline** onto the results page (collapse-to-summary layout) so you can edit the view and regenerate in place.
- **Native datepicker** on the date fields.
- **Copy-as-Markdown / CSV** incorporate the per-row summaries.

## 3. Non-goals (out of scope for 1.4.0)

- Per-row summaries for the `user` and `total` breakdowns — those keep a single report-level summary (see §6.6).
- Streaming token-by-token summary rendering.
- Any change to the deduped hours/attribution math (the 1.3.0 billing-correctness work is untouched).
- Sending comments to the AI (rejected: too much PII surface for the gain).

## 4. Decisions locked in this session

| # | Decision | Choice |
|---|---|---|
| D1 | AI payload | Subtask titles + hours, **and** task/subtask descriptions |
| D2 | Descriptions gate | **Admin opt-in setting**, off by default (`ConfigModel`) |
| D3 | Per-row granularities | `task`, `day`, `week` (the active breakdown's rows) |
| D4 | Call model | Per-row, **lazy on-demand**, content-hashed cache |
| D5 | Aggregates (day/week) | AI **summary-of-cached-task-summaries** |
| D6 | Cache key | Row identity **+ content hash** only — shared across AI profiles and users |
| D7 | Stale behavior | Serve cached **+ "may be outdated" badge**; regenerate only on click |
| D8 | Copy/CSV | **"Generate all"** fills every row (via cache), then export includes them |
| D9 | Row UI | **Expandable row** (narrative + highlights + regenerate) |
| D10 | Controls layout | **Option B — collapse-to-summary**, all controls inline |
| D11 | Date fields | Native Kanboard datepicker |
| D12 | Deliverable | This spec → fresh SDD build agent |

## 5. Domain model (new/changed terms)

- **DetailRow** (extended): now also carries `description` (task) and `subtasks: [{title, status, hours, description}]`. `description` fields are **empty unless the admin opt-in is on**.
- **Row** — a single line of the active breakdown. Identity: `task` → task id; `day` → `YYYY-MM-DD`; `week` → ISO `o-\WW`.
- **Content hash** — a stable digest of everything about a Row that should change its summary. Because **subtask edits do not bump any task timestamp and subtasks have no timestamps of their own** (verified against core), the hash must digest subtask row content directly, not a timestamp:
  - **Task row hash** = `sha1( task.date_modification | subtask[ title|status|time_spent|position ]* | descriptions? )`, where `descriptions?` is included only when the admin opt-in is on (so flipping the setting invalidates correctly).
  - **Aggregate row hash** (day/week) = `sha1( sorted(member_task_id : member_task_content_hash)* )`. Changes when the row's task set changes or any member's content changes.
- **Cached summary** — `{ hash, summary, highlights[], generated_at }`. A row is **fresh** when its stored `hash` equals the freshly-computed content hash, **stale** otherwise, **missing** when absent.

## 6. Design

### 6.1 Data — gather subtasks (Model)

- New `TimeReportModel::gatherSubtasksForTasks(array $taskIds): array<int, list<subtask>>` — one query over `SubtaskModel::getAll` shape (`id, title, status, time_spent, time_estimated, position, task_id`), optionally joined to fetch task/subtask `description`.
- `buildDetail()` attaches `subtasks` and `description` to each DetailRow. Descriptions are **omitted at gather time** when the opt-in is off, so they never enter the process at all.

### 6.2 Content hashing (Model, pure + unit-testable)

- `TimeReportModel::taskContentHash(DetailRow $row, bool $includeDescriptions): string`
- `TimeReportModel::aggregateContentHash(array $memberTaskHashes): string`
- Both pure functions of their inputs → trivially unit-tested for stability and invalidation.

### 6.3 Cache store

- **Task summaries** → `task_has_metadata` via `TaskMetadataModel::save/get`, key `timereport_ai_summary`, value = JSON `{hash, summary, highlights, generated_at}`. One entry per task (shared across profiles per D6); regenerate overwrites.
- **Aggregate summaries** → `project_has_metadata` via `ProjectMetadataModel`, key `timereport_ai_agg`, value = JSON map `{ "<granularity>:<rowkey>": {hash, summary, highlights, generated_at} }`. Pruned opportunistically to keep the TEXT value bounded.
- New thin `AiSummaryCache` model wraps read/write/hash-check so the controller and CSV export share one code path.

### 6.4 AI generation (AiSummaryModel)

- `summarizeTask(DetailRow $row, ?string $profileId): {summary, highlights}` — same schema; prompt updated to use subtasks (and descriptions when present). System prompt gains: "Each task includes its completed subtasks and, when available, descriptions; ground the narrative in those."
- `summarizeAggregate(string $granularity, string $rowLabel, array $memberTaskSummaries, ?string $profileId): {summary, highlights}` — summary-of-summaries over the member tasks' cached `{summary, highlights}` (D5). No raw re-send of task data.
- Boundary note updated in the class docblock: descriptions are forwarded **only when the admin opt-in is enabled**; comments never.

### 6.5 Endpoints (Controller)

All new actions extend `BaseController`, enforce login + `assertProjectAccess`, and return `$this->response->json(...)`.

- **`rowSummary`** (POST, CSRF): params `project_id, granularity, row_key, force?`. Logic:
  1. Rebuild the report context for `project_id` + range (range/scope passed through hidden fields) and locate the Row.
  2. Compute the current content hash.
  3. `force` → regenerate + overwrite cache, return `{stale:false}`.
  4. Cache **fresh** → return cached `{stale:false}`.
  5. Cache **stale** → return cached `{stale:true}` (no spend — D7).
  6. Cache **missing** → generate, cache, return `{stale:false}`.
  - For `day`/`week`: member task summaries are fetched from cache; any missing member is generated (and cached) first, then composed. Staleness of any member ⇒ aggregate hash mismatch ⇒ `{stale:true}`.
- The report-context rebuild reuses `TimeReportModel::report()` — **no new mining path**, so numbers can't diverge from the rendered table.

### 6.6 Rendering (Templates + JS)

- **`_breakdown.php`**: for `task`/`day`/`week`, each row gets an expander affordance and a lazily-populated detail row. Icons: not-generated (`chevron`), cached (`sparkles`), expanded (`chevron-down`). On expand, JS calls `rowSummary`; renders narrative + highlights; shows the **"may be outdated"** badge + **Regenerate** when `stale`.
- **`show.php`**: replace the bare actions with the **Option B control bar** — a one-line summary (`Project · range · breakdown · scope · +AI`) with **Edit filters** expanding the full control set (project, date range **with datepicker**, breakdown, scope, detail, AI, profile) that re-submits to `generate`. Right-aligned actions: **Update**, **Generate all summaries**, **Copy as Markdown**, **Export CSV**.
- **`user`/`total` breakdowns**: keep the existing single report-level `{summary, highlights}` block (the old behavior), computed once. `total` is effectively a one-row report; `user` per-row is a future enhancement.
- **`form.php`**: swap `form->text` date fields for `form->date` (datepicker). Privacy note reflects the current opt-in state.

### 6.7 Copy / CSV (D8)

- **Generate all summaries**: JS iterates the visible summarizable rows, calling `rowSummary` (cache-respecting, sequential with a small concurrency cap + progress), populating cache and the DOM.
- **Copy as Markdown**: assembled **client-side** from the rendered table + loaded summary panels (replaces the static hidden-textarea payload), so it includes whatever summaries are present.
- **CSV**: `exportCsv` gains an optional **Summary** column populated **server-side from cache** (cache-only, generates nothing). Run *Generate all* first for a complete export; uncached/stale rows export blank. The existing `scope_denied` refusal is unchanged.

### 6.8 Admin opt-in (D2)

- Attach a checkbox to `template:config:integrations` (Kanboard's Settings → Integrations): `timereport_send_descriptions`, saved via `ConfigModel`, **off by default**.
- `AiSummaryModel` / gather layer reads it to decide whether descriptions are gathered and forwarded. Flipping it invalidates task hashes (descriptions are part of the hash), so summaries regenerate correctly.

## 7. Task breakdown (SDD — ordered, each independently testable)

> TDD throughout: failing test → minimum implementation → green → commit. Each task ships with unit tests; UI tasks add a live-verification note against the dev stack (`testing/docker-compose.dev.yml`, `:8081`, admin/admin).

1. **Subtask gathering** — extend DetailRow with `subtasks` + `description`; `gatherSubtasksForTasks`. Tests: rows carry subtasks; descriptions absent when opt-in off.
2. **Admin opt-in setting** — `template:config:integrations` checkbox + `ConfigModel` read/write; default off. Tests: default off; save/read; gather honors it.
3. **Content hashing** — `taskContentHash` / `aggregateContentHash` pure functions. Tests: stability; subtask edit flips hash; task-description edit flips hash **only when opt-in on**; aggregate flips on member set/content change.
4. **Cache model** — `AiSummaryCache` over task + project metadata; fresh/stale/missing classification. Tests: round-trip; hash-match ⇒ fresh; mismatch ⇒ stale.
5. **AiSummaryModel: per-task** — `summarizeTask` with subtasks/descriptions; prompt update. Tests: payload includes subtasks; descriptions only when opt-in on; comments never.
6. **AiSummaryModel: aggregate** — `summarizeAggregate` (summary-of-summaries). Tests: composes from member summaries; no raw task re-send.
7. **`rowSummary` endpoint** — access-guard + CSRF + the fresh/stale/missing/force state machine (§6.5). Tests: each branch; stale returns without generating; force overwrites.
8. **Breakdown expandable-row UI** — `_breakdown.php` + JS lazy load, stale badge, regenerate. Live-verify expand/regenerate/stale.
9. **Inline control bar (Option B) + datepicker** — `show.php` collapse-to-summary controls re-submitting to `generate`; `form->date`. Live-verify edit-and-regenerate, datepicker.
10. **Generate all + client-side Markdown** — bulk fill with progress; DOM-assembled Copy. Tests (JS assembler where feasible) + live-verify.
11. **CSV summary column** — server-side cache-only Summary column. Tests: cached row included; uncached blank; `scope_denied` still refuses.
12. **Docs + version** — README privacy section (subtasks + optional descriptions), `CHANGELOG.md` 1.4.0, version bump across the three files (§8).

## 8. Release (standing approval granted this session)

Per the suite methodology — buildless, **tag == version across three files**:

- Bump to **1.4.0** in `plugin.json` (`version`), `Plugin.php` (`getPluginVersion()`), and add the `CHANGELOG.md` entry. The CI fails the release on any drift.
- **Standing approval:** once the build agent completes and **all tests pass** (implementer + reviewer green), commit, tag `v1.4.0`, and push — the zip-on-tag workflow cuts the release. Verify the published asset is a clean single-folder zip that downloads 200.
- After release, bump the entry in `kanboard-modmenu-directory/plugins.json`.

## 9. Risks / watch-list

- **Aggregate cache growth** in `project_has_metadata` (one TEXT row) — prune old keys; consider a size cap.
- **Cost of "Generate all"** on a wide report — sequential with a small cap + progress; task summaries are reused by day/week so the marginal cost of aggregates is low.
- **CSP** — all JS stays external + event-delegated (as today); no inline handlers.
- **Descriptions PII** — off by default; the opt-in copy must state descriptions are sent to the configured AI provider.
- **Prompt/model** — richer data should lift quality on gpt-5-luna; the build agent should sanity-check one real report before release.
