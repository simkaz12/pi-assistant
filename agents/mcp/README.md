# MCP servers

Three servers built on `laravel/mcp`, mounted in a self-hosted Laravel admin app. The code here is the integration layer lifted out of that app, so it is PHP and it references classes (models, migrations, the `User` model) that are not in this repo. Read it as source, not as a package.

| Directory | Server | Path |
| --- | --- | --- |
| `oura/` | `pi-oura` | `/mcp/oura` |
| `training-nutrition/` | `pi-training-nutrition` | `/mcp/training-nutrition` |
| `system/` | `pi-system` | `/mcp/system` |

Shared pieces:

- `routes/mcp.php` registers each server behind `vpn` and a token middleware. `routes/console.php` is the matching schedule. The Oura and training entries are guarded by control-center switches.
- `middleware/` has `EnsureWireguardNetwork` (CIDR allow-list from `config/mcp.php`) and one bearer-token check per server. All compare with `hash_equals`. An unset token answers 503, so a missing secret fails closed.
- `config/` has the config files the code reads. Secrets come from the environment.

The original app documents why there is no GET handler: PHP-FPM cannot hold an SSE stream, and `laravel/mcp` answers GET with 405, which the MCP spec allows. Clients retry twice, give up, and POST calls keep working.

Environment variables (see `.env.example`): `OURA_CLIENT_ID`, `OURA_CLIENT_SECRET`, `OURA_REDIRECT_URI`, `OURA_HERMES_TOKEN`, `OURA_USER_ID`, `TRAINING_NUTRITION_USER_ID`, `TRAINING_NUTRITION_MCP_TOKEN`, `SYSTEM_MCP_TOKEN`, `MCP_TRUSTED_CIDRS`.
