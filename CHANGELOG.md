# Changelog

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
