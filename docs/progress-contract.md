# TimeReport progress — theme contract v1

TimeReport ≥ 1.5.0 renders three server-side surfaces. Themes (e.g. Battle Lobby) restyle them with CSS
and may read the `data-tr-*` values from external JS. No inline script is involved, so the contract is CSP-safe.

**Stability:** within v1, classes and attributes are only ever **added**, never renamed or removed. A breaking
change bumps `data-tr-contract` and TimeReport's major version. `Test/fixtures/progress-contract.html` is the
rendered reference.

| Surface | Hook | Visible in stock UI |
|---|---|---|
| Card progress `.tr-progress` | `template:board:task:footer` | yes |
| Pass track `.tr-track` | `template:project:header:after` | no (`display:none`) |
| Level badge `.tr-level` | `template:layout:top` | no (`display:none`) |

## Card progress

```html
<div class="tr-progress" data-tr-contract="1" data-tr-task="42"
     data-tr-subtasks-done="3" data-tr-subtasks-total="5" data-tr-pct="60"
     data-tr-time-spent="3.4" data-tr-time-est="4" data-tr-time-state="ok">
  <div class="tr-progress__bar" role="progressbar" aria-valuenow="60" style="--tr-pct:60%">
    <span class="tr-progress__fill"></span><span class="tr-progress__label">3/5</span>
  </div>
  <div class="tr-progress__time tr-progress__time--ok" style="--tr-pct:85%">
    <span class="tr-progress__track"><span class="tr-progress__fill"></span></span>
    <span class="tr-progress__label">3.4/4h</span>
  </div>
</div>
```

- The subtask attributes and `.tr-progress__bar` appear only when the task has subtasks.
- The time attributes and `.tr-progress__time` appear only when the task has an estimate. `data-tr-time-state` is `ok` or `over`; the width is capped at 100%.
- `.tr-progress__time` carries the modifier `.tr-progress__time--ok` or `.tr-progress__time--over`, mirroring `data-tr-time-state`.
- A task with neither renders nothing.

## Pass track

`section.tr-track[data-tr-contract][data-tr-project][data-tr-project-pct]` contains `.tr-track__project`
(`--tr-pct`, `.tr-track__fill`, `.tr-track__count`) and `ol.tr-track__milestones` > `li.tr-track__milestone`
with `data-tr-milestone`, `data-tr-pct`, `data-tr-due` (`Y-m-d`, or empty when undated), `data-tr-state="open"`
and its own `--tr-pct`; each milestone contains `.tr-track__title` (the milestone task's title) and `.tr-track__count`
(closed/total member tasks).
It holds open milestones (core "is a milestone of" links), dated first by due date, then undated by id.
A project with no tasks renders nothing.

## Level badge

`span.tr-level[data-tr-contract][data-tr-user][data-tr-level][data-tr-xp][data-tr-next]` with `.tr-level__label`.
`data-tr-next` is the XP still needed for the next level. `data-tr-party-xp` (own + agents' XP) appears only
when the Agents plugin is installed and the user owns agents. It renders nothing when logged out.
