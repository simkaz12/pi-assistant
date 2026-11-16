# systemd units

| Unit | What | Notes |
| --- | --- | --- |
| `pi-queue` | `php artisan queue:work redis --queue=default,media --sleep=3 --tries=3 --max-time=3600` | Runs jobs for the admin app, including the Oura sync and the switch applier. `--max-time` makes it restart hourly, so memory cannot creep. |
| `pi-reverb` | `php artisan reverb:start --host=127.0.0.1 --port=6001` | Websocket server, reached through nginx at `/ws/`. |
| `pi-now-watch` | `php artisan now:watch` | Polls local services every few seconds and pushes only changes over Reverb. |
| `pi-now-machines` | `php artisan now:watch --feed=machines` | Separate process for machine stats: an offline machine costs about two seconds per call, which would stall the other feed. |
| `hermes-gateway` | `hermes gateway run` | System unit, not a user unit. Restart with `sudo systemctl restart hermes-gateway`. `RestartForceExitStatus=75` lets Hermes ask for its own restart. |
| `tabularium-mcp` | memory MCP server | Bound to the VPN address, so it is unreachable from the LAN or the internet. `RequiresMountsFor=/mnt/media` keeps it from starting before the data disk is mounted. |

Install:

```bash
sudo cp *.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now pi-queue pi-reverb pi-now-watch pi-now-machines tabularium-mcp hermes-gateway
```

Adjust `User`, `WorkingDirectory` and the PHP path to your install. The `pi-*` units run as the deploy user with group `www-data`, and `pi-queue` sets `UMask=0002` so files it writes stay group-writable for php-fpm.
