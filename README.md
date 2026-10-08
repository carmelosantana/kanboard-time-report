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

The per-row cache is keyed by row identity plus a content hash only — it is **shared across AI profiles** (and users). The selected profile decides which provider generates a summary on a miss or a **Regenerate**; the result is cached under that shared key, so the **last generation wins** regardless of which profile produced it.

### What is sent to the AI provider

When you add an AI summary, TimeReport sends the configured [AiConnector](https://github.com/carmelosantana/kanboard-ai-connector) provider, for each completed task in scope:

- task **title**, attributed **hours**, **category**, **tags**, and **completion date**;
- the task's **completed subtasks** (title and hours).

**Task descriptions are sent only when an administrator opts in** at *Settings → Integrations → "Send task descriptions to the AI provider"*, which is **off by default**. Enable it only if descriptions do not contain information you would not want to leave your Kanboard instance.

**Comments are never sent.** No data is sent to any provider unless you explicitly request an AI summary, and nothing is sent at all when AiConnector is absent or unconfigured.

## Progress and XP

Board cards show a **subtask completion bar** (done/total) and, when the task has an estimate, a thin **time-vs-estimate meter** that turns red on overrun. Both render through the `template:board:task:footer` hook; no core template is overridden. A task with neither subtasks nor an estimate shows nothing.

TimeReport also tracks **project progress**, **milestone progress** (tasks linked with core's "is a milestone of" link) and **XP with levels**. The pass track and level badge are emitted hidden in the stock UI so themes can restyle them; see [`docs/progress-contract.md`](docs/progress-contract.md) for the theme contract (classes and `data-tr-*` attributes).

### XP rules

| Event | XP |
|---|---|
| Subtask completed | 10 to its assignee |
| Task closed | 25 + 10 × complexity to its assignee |

Level L starts at **50·L·(L−1)** XP (level 2 at 100, level 3 at 300, level 4 at 600). Unassigned work earns no XP. With the **Agents** plugin installed, an agent's XP also rolls into its owner's party total. The report shows XP earned in the selected range alongside hours (control bar, Markdown and CSV).

### JSON-RPC

All read-only and checked against the caller's project permissions.

| Method | Returns |
|---|---|
| `getTaskProgress(task_id)` | One task's subtask and time meters. |
| `getProjectProgress(project_id)` | A project's closed/total tasks, percentage and its milestones. |
| `getMilestoneProgress(task_id)` | A milestone task's progress over the tasks linked to it. |
| `getUserXp(user_id, project_id?, from?, to?)` | A user's XP and level (lifetime, or for a project/date range), per project, plus a party total when they own agents. |
| `getXpLeaderboard(project_id, from?, to?)` | XP per user on a project, highest first. |

Deleting a task, project or user fires no core event, so cached lifetime XP refreshes on the next task or subtask event.

### Replacing TaskProgressBar

TimeReport's card meters replace the third-party TaskProgressBar plugin. While both are installed, TaskProgressBar's bar is hidden so each card shows one bar, and admins see a notice (dismissible per user).

1. Deploy TimeReport 1.5.0.
2. Open a board and check the cards show TimeReport's meters.
3. Delete `plugins/TaskProgressBar`.
4. Confirm the admin notice is gone.

To roll back, restore the `plugins/TaskProgressBar` folder.

## License

MIT. See LICENSE for details.
