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

TimeReport integrates with the [AiConnector](https://github.com/carmelosantana/kanboard-ai-connector) plugin to add an optional AI-generated narrative summary of the completed work. If AiConnector is not installed, the report displays the hours table alone—no loss of core functionality.

## License

MIT. See LICENSE for details.
