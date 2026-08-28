# Changelog

## 1.3.0 — 2026-08-27

### Fixed

- **Hours logged by one person could be billed to another.** Kanboard stores a task's `time_spent` as the sum of *all* its subtasks' time, across every user and every period. The report treated that pool as the task owner's whenever the people who actually tracked the time were filtered out — so a task you merely owned could add someone else's hours to your report. Time is now attributed to whoever tracked it, and a task's own total is only ever used when nobody tracked time on it at all, in any period. Reports may show fewer hours than before; the earlier figures were overstated.
- **The untracked-time warning could report time that was in fact tracked.** A subtask's recorded time is a shared pool, but it was being compared against only one person's tracked hours — so when the person who logged the time was not the person the subtask was assigned to, the difference was reported as untracked. The comparison now uses every logger's tracked time.
- Unassigned tasks (no owner) no longer contribute their time to any user's report.

### Added

- Report on other users' hours: project managers and administrators can include any user who logged time in the project, then refine the set from a **Users** panel on the results page to bill everyone or a subset.
- New **By user** breakdown, grouping hours per person.
- Completed-task detail gains an **Assignee** column when the report covers more than one user; single-user reports are unchanged.

### Security

- Members and viewers keep the self-only report. A request to include others without permission narrows to your own hours and says so on the page, and a CSV export of such a request is refused rather than silently downloaded.

## 1.2.0 — 2026-08-23

- Added **Generate report** and **Time Report** links to each project's ≡ menu: "Generate report" jumps straight to that project's report for the current month grouped by task; "Time Report" opens the report form with the project pre-selected.
- The report form now accepts a `project_id` in the URL and pre-selects that project.

## 1.1.0 — 2026-08-22

- Show the day of week on report dates (e.g. `Mon 2026-08-10`) on-screen and in the Copy-as-Markdown output; the CSV export keeps bare ISO dates.
- Click (or press Enter/Space on) any hours value — the per-period rows, the completed-task rows, and the grand total — to copy it to the clipboard, with a brief flash and a "Copied ✓" badge, for quick single-value data entry.

## 1.0.0 — 2026-08-21

- Initial release: self-only consultant hours report for one project over a date range.
- Deduped hours union of subtask time entries and task-level time_spent.
- Breakdowns by day, week, task, or total; optional completed-task detail.
- Delivery: on-screen HTML, Copy-as-Markdown, CSV export.
- Optional AI narrative summary via AiConnector (degrades to fully manual when absent).
- Warn on-screen when a subtask has manually-entered time that isn't date-tracked (and so isn't counted), showing the untracked amount per task.
