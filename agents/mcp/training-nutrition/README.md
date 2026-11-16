# pi-training-nutrition

Training plans, performed workouts and an observational food and drink log, kept as stored facts for an agent to reason over. The server does not generate advice.

Server: `TrainingNutritionServer.php`, tools in `tools/`, domain code in `module/` (models, services, DTOs, the reconcile command). Auth: `AuthenticateTrainingNutritionMcpMiddleware`, which also resolves the single owner account.

| Tool | Actions |
| --- | --- |
| `training_profile` | `get`, `update`, `exercises`, `save_exercise` |
| `training_plan` | `save`, `get`, `history`, `activate` |
| `training_session` | `start`, `log`, `amend`, `complete`, `get`, `history`, `link`, `unlink`, `reconcile` |
| `nutrition_log` | `record`, `amend`, `get`, `history`, `summary` |
| `training_nutrition_data` | `timeline`, `export`, `delete_preview`, `delete_confirm` |

Rules enforced in code and stated in the tool descriptions:

- `training_plan.save` stores a proposal and never activates it. `activate` needs the owner's explicit acceptance, or the id of a rule already in the accepted plan.
- A session completed without "as prescribed" leaves unreported numbers unknown rather than copying the plan.
- Nutrition records the owner's report. The agent interprets meal photos itself and sends structured observations. The server never receives images, and label values keep their serving basis.
- Mutations are idempotent through a `request_id`, recorded in an operations table with a fingerprint.
- Deletion needs exact targets or a bounded dated scope, then a ten-minute single-use token. It never touches the underlying Oura, chat or backup data.

## Oura reconciliation

`module/Services/TrainingWorkoutReconciliationService.php` matches stored Oura workouts to logged sessions. `php artisan training:reconcile-workouts` processes one bounded batch (50 by default) and keeps a document-id cursor, so a run every five minutes works through history without long locks. A link needs time overlap of at least `match_overlap` (0.8). Evidence is counted once, so a workout shared by a session and a ring record is not doubled. Matches that cannot be decided are stored as `unresolved` for the agent to ask about.
