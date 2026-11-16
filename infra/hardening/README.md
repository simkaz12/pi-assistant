# Hardening

| File | Install to | What |
| --- | --- | --- |
| `ufw.sh` | run once as root | Default-deny allow-list firewall |
| `fail2ban/jail.local` | `/etc/fail2ban/jail.local` | `sshd`, `nginx-botsearch`, `nginx-4xx-burst` |
| `fail2ban/filter.d/nginx-4xx-burst.conf` | `/etc/fail2ban/filter.d/` | Counts 403, 404 and 444 responses per address |
| `apt/20auto-upgrades` | `/etc/apt/apt.conf.d/` | Daily package list update and upgrade |
| `apt/52unattended-upgrades-local` | `/etc/apt/apt.conf.d/` | Security origin only, cleanup, reboot at 04:00 |

## Firewall

Open to anyone: 22/tcp, 53, 80/tcp, 443/tcp, 51820/udp. LAN and VPN only: 8053 (Pi-hole admin), 51821 (wg-easy UI), and from the VPN 8787 (memory MCP). Routed traffic is denied except between `eth0` and `wg0`.

Docker publishes ports by writing its own iptables rules, which ufw does not see. That is why the compose files bind application ports to `127.0.0.1` instead of relying on ufw to block them.

## fail2ban

- Ban action is `ufw`. The nginx jails use the `Nginx Full` profile, so they block 80 and 443 only. An address banned for web probing can still reach SSH and WireGuard, which keeps a mistaken ban from locking the owner out.
- `ignoreip` covers loopback, the VPN subnet and the LAN.
- `nginx-4xx-burst` bans for six hours after 20 responses of 403, 404 or 444 in five minutes. Because the internal vhosts answer outsiders with 444, anything hammering them trips this jail.

## Unattended upgrades

Debian security updates install on their own, packages nothing depends on are removed, and the Pi reboots at 04:00 when a kernel or library update asks for it. Docker images are not covered: Diun reports them and I update by hand.
