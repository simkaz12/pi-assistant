# Oura integration

Collects ring data from the Oura API v2 into a local table so an agent can query it without hitting the API at request time. It runs inside a self-hosted Laravel admin app. The MCP tools that read the data are in `../mcp/oura`.

```
oura_connection tool -> OuraConnectionService::authorize -> consent URL (10 min)
Oura -> /oura/oauth/callback -> OuraOAuthController -> callback() -> tokens stored encrypted
callback() -> one SyncOuraDataset job per dataset -> OuraSyncService::backfillPage
scheduler -> oura:sync recent | history | older | prune
```

## OAuth

- `authorize()` creates a random `state`, stores only its SHA-256 and a ten-minute expiry, and returns the consent URL. The callback consumes the state once.
- `OuraClient::exchange()` posts the authorization code and maps Oura's error codes to a fixed set, so a failure is stored as a short code, never a raw response.
- Access and refresh tokens use Laravel's `encrypted` cast. Refresh is guarded by a `refresh_started_at` lease so two workers cannot rotate the token at once. A `generation` counter invalidates in-flight jobs when the connection is cancelled or re-authorized.
- Scopes: `daily`, `heartrate`, `tag`, `workout`, `session`, `spo2`, `ring_configuration`, `stress`, `heart_health`.

## Sync

`OuraDatasetRegistry` lists the 18 datasets and whether each is keyed by date or datetime. `oura:sync <mode>` modes:

| Mode | Schedule | What |
| --- | --- | --- |
| `recent` | hourly | Re-fetches the last seven days of every dataset. |
| `history` | 02:00 | Continues initial backfills that are `pending`, `importing` or `partial`. |
| `older` | 02:30 | Walks a reconcile cursor through completed history in windows of up to 31 days, so late corrections to old data are picked up. |
| `prune` | 03:30 | Deletes expired deletion-preview tokens. |

`SyncOuraDataset` is a queued job that retries later on rate limits (`OuraRetryLater`). Documents are upserted by Oura's id with a run id and window timestamp, so a window that no longer contains a record can retire it.

## Files

| Path | What |
| --- | --- |
| `Http/` | OAuth callback controller and request validation |
| `Services/OuraConnectionService.php` | authorize, callback, disconnect, status |
| `Services/OuraClient.php` | token exchange, refresh, paged fetch |
| `Services/OuraSyncService.php` | backfill pages and reconcile windows |
| `Services/OuraContextService.php` | read model behind `wellbeing_context` |
| `Services/OuraDataService.php` | export and two-step deletion |
| `Commands/SyncOura.php`, `Jobs/SyncOuraDataset.php` | scheduler entry and queue job |
| `Models/` | connection, sync state, stored documents |

The callback route is `GET /oura/oauth/callback`, behind the `vpn` middleware.
