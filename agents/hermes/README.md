# Hermes Agent wiring

Hermes Agent is an open-source project by NousResearch: [NousResearch/hermes-agent](https://github.com/NousResearch/hermes-agent). This directory holds only what I added around an unmodified install (v0.21.7).

| File | What |
| --- | --- |
| `config.example.yaml` | The `mcp_servers` block and the cron setting, with tokens as `${VARIABLES}` |
| `.env.example` | Variables the config refers to |
| `system_watcher.py` | A `no_agent` cron script that turns system problems into messages |
| `test_system_watcher.py` | Unit tests for the alert logic, no network |
| `../../infra/systemd/hermes-gateway.service` | Gateway unit |

## MCP servers Hermes consumes

| Name | Endpoint | Auth |
| --- | --- | --- |
| `system` | `/mcp/system` on the admin app | bearer token, tool list limited to `system_status`, `system_problems`, `system_events` |
| `oura` | `/mcp/oura` | bearer token |
| `training-nutrition` | `/mcp/training-nutrition` | bearer token |
| `tabularium` | `http://10.8.0.1:8787/mcp` | none, VPN-only bind |

Each token is separate, so revoking one server's access leaves the others working.

## System watcher

`system_watcher.py` runs as a Hermes cron job with `no_agent`, which means Hermes runs the script and delivers its stdout without calling a model. It prints nothing when there is nothing to say. A failure exits non-zero and Hermes posts its own watchdog message.

Behaviour:

- The first run saves a silent baseline (the currently critical keys and the newest notice id) in `~/.hermes/cron/state/system-watcher.json`.
- A critical problem is reported once, after it has been critical for five minutes (the `alertable` flag from the MCP server). One `RESOLVED` line follows when it clears.
- Warnings never go to Telegram.
- Notices, such as a failed switch change, are forwarded verbatim.
- Delete the state file to re-baseline.

The script reads its token from `~/.hermes/secrets/pi-system-mcp-token` and only from there, because Hermes scrubs the environment of cron jobs. It connects to `127.0.0.1:443` but sends the real `Host` header and verifies the certificate against the real name, so it keeps working when DNS is down and never leaves the box.

### One-time setup

1. Set `SYSTEM_MCP_TOKEN` in the admin app `.env` (`openssl rand -hex 24`), then rebuild its config cache and restart php-fpm and the queue worker.
2. Write the same token to `~/.hermes/secrets/pi-system-mcp-token` with mode 600 and no trailing text.
3. Add `MCP_SYSTEM_API_KEY=<token>` to `~/.hermes/.env` for the gateway.
4. Merge `config.example.yaml` into `~/.hermes/config.yaml`. `cron.wrap_response: false` applies to every cron job and stops Hermes adding its own header to script output.
5. Copy `system_watcher.py` to `~/.hermes/scripts/` with mode 700.
6. Create the job:

   ```bash
   hermes cron create "every 1m" --no-agent --script system_watcher.py --deliver telegram --name "System watcher"
   ```

7. Restart the gateway: `sudo systemctl restart hermes-gateway`.

### Tests

```bash
python3 -I test_system_watcher.py
```

## Pausing jobs from the admin app

The control center in the admin app keeps one `system_switches` row per switch. For a Hermes job, the `ApplySwitch` queue job looks the job id up in `~/.hermes/cron/jobs.json` and runs `hermes cron pause <id>` or `hermes cron resume <id>`. If that fails, the row reverts and a notice event is written, which the watcher then delivers. Code: `../mcp/system/module`, definitions: `../mcp/config/system.php`.

## Known issue

Telegram connectivity flapped over IPv6 on this network, so `network.force_ipv4: true` is set in the Hermes config.
