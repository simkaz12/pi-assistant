# pi-system

Read-only health of the Pi. It feeds the Hermes system watcher and a dashboard.

Server: `SystemServer.php`, tools in `tools/`, collector and rules in `module/`. Auth: `VerifySystemMcpToken`.

| Tool | Returns |
| --- | --- |
| `system_status` | The latest collected snapshot: host stats, systemd units, containers, scheduled task runs, queue, certificate days, dynamic DNS check, WireGuard peers. `snapshot` is null before the first run. |
| `system_problems` | Open problems, critical first. Each has `key`, `severity`, `title`, `detail`, `since` and `alertable` (true once critical for five minutes). A `collector:stale` problem appears when the snapshot is older than three minutes. |
| `system_events` | The problem and notice log in ascending id order. `after_id` pages forward, `limit` is clamped to 1..200, `kind` filters `problem_opened`, `problem_resolved` or `notice`. |

## How the data is produced

- `php artisan system:collect` runs every minute. `Probes/ShellHostProbe` runs `systemctl` and `docker`, reads host stats, and reads Hermes' `jobs.json` and execution database (read-only). Slow probes such as the certificate check are cached for ten minutes.
- `Services/HealthRules` turns the snapshot into findings using the thresholds in `../config/system.php`. `ProblemTracker` diffs findings against the open set and writes `system_events` rows when a problem opens or resolves.
- Other modules write `notice` events through the `ProducesNotice` contract and the `RecordNotice` listener. That is how a finished background job reaches Telegram without the app knowing about Telegram.

## Control-center switches

`Services/Switches`, `Jobs/ApplySwitch` and `Models/SystemSwitch` implement on/off switches for scheduled work. The definitions are in `../config/system.php`:

| Kind | Applied by | Example |
| --- | --- | --- |
| `schedule` | `->skip()` on the Laravel schedule entry, which reads the table | `oura-sync`, `training-reconcile` |
| `hermes` | `ApplySwitch` on the queue: `hermes cron pause\|resume <id>` | `system-watcher` |

A failed apply reverts the row and writes a notice. A switched-off job raises no "did not run" problem.
