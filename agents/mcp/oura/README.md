# pi-oura

Private surface for stored Oura ring data. The data is collected by the sync code in `../../oura`; this server only reads it, plus manages the connection.

Server: `OuraServer.php`. Auth: `VerifyOuraToolsToken`.

| Tool | Actions | Notes |
| --- | --- | --- |
| `oura_connection` | `authorize`, `status`, `disconnect` | `authorize` returns a consent URL valid for ten minutes. `disconnect` stops collection and deletes credentials but keeps history. |
| `wellbeing_context` | `today`, `sleep`, `recovery`, `stress`, `activity`, `trends`, `correlations`, `raw` | Dates are owner-local `YYYY-MM-DD`. `trends` needs `metric`, `raw` needs `dataset` and takes `cursor` and `limit`. Responses state coverage and freshness. |
| `oura_data` | `export`, `delete_preview`, `delete_confirm` | Paged export. Deletion is two steps with a single-use token for the exact range. Deleting the whole history also disconnects. |

The server instructions tell the agent that missing observations are unknown and that it must not infer medical diagnoses.
