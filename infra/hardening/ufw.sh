#!/usr/bin/env bash
set -euo pipefail

LAN=192.168.1.0/24
VPN=10.8.0.0/24

ufw default deny incoming
ufw default allow outgoing
ufw default deny routed

ufw allow 22/tcp comment 'SSH'
ufw allow 53 comment 'Pi-hole DNS'
ufw allow 80/tcp comment 'HTTP redirect'
ufw allow 443/tcp comment 'HTTPS'
ufw allow 51820/udp comment 'WireGuard'

for port in 8053 51821; do
    ufw allow from "$LAN" to any port "$port" comment "LAN $port"
    ufw allow from "$VPN" to any port "$port" comment "VPN $port"
done

ufw allow from "$VPN" to any port 8787 proto tcp comment 'memory MCP'

ufw route allow in on eth0 out on wg0
ufw route allow in on wg0 out on eth0

ufw --force enable
ufw status verbose
