# TimeReport

Consultant hours reporting for Kanboard: pick a project and date range, choose per-day/per-week/per-task breakdowns, list completed tasks, and optionally add an AI summary. Copy as Markdown or export CSV.

## Purpose

TimeReport aggregates completed work and time spent across a project over a specified date range, presenting it in formats suitable for client reporting. It deduplicates hours from both subtask time entries and task-level `time_spent` fields, offering flexible grouping by day, week, task, or total hours.

## Install

1. Download the latest release ZIP from GitHub.
2. Extract into your Kanboard `plugins/` directory as `plugins/TimeReport/`.
3. Enable the plugin in Kanboard's admin panel under Plugins.

## Usage

### Three Surfaces

- **Report View**: Select a project, date range, and breakdown type (by day, week, task, or total). The on-screen HTML table displays all completed tasks and aggregated hours in your chosen grouping.
- **Copy as Markdown**: One-click copy of the report to clipboard in Markdown format, ready to paste into a document or email.
- **Export CSV**: Download the report as a CSV file for further analysis or invoice line-item creation.

## AI Optional — Degrades Gracefully

TimeReport integrates with the [AiConnector](https://github.com/carmelosantana/kanboard-ai-connector) plugin to add optional AI-generated narrative summaries of the completed work. If AiConnector is not installed, the report displays the hours table alone — no loss of core functionality, and no AI controls are shown.

### Per-row summaries

For the per-day, per-week, and per-task breakdowns, each row can be expanded to a summary of the work it covers. Summaries load on demand, are cached (they survive across reports), and show a **"may be outdated"** badge with one-click **Regenerate** when the underlying work changed. **Generate all summaries** fills every row at once; **Copy as Markdown** includes whatever summaries are loaded, and the CSV export gains a **Summary** column populated from cache.

### What is sent to the AI provider

When you add an AI summary, TimeReport sends the configured [AiConnector](https://github.com/carmelosantana/kanboard-ai-connector) provider, for each completed task in scope:

- task **title**, attributed **hours**, **category**, **tags**, and **completion date**;
- the task's **completed subtasks** (title and hours).

**Task descriptions are sent only when an administrator opts in** at *Settings → Integrations → "Send task descriptions to the AI provider"*, which is **off by default**. Enable it only if descriptions do not contain information you would not want to leave your Kanboard instance.

**Comments are never sent.** No data is sent to any provider unless you explicitly request an AI summary, and nothing is sent at all when AiConnector is absent or unconfigured.

## License

MIT. See LICENSE for details.
