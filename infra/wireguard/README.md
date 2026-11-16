# WireGuard with wg-easy

`docker-compose.yml` runs [wg-easy](https://github.com/wg-easy/wg-easy) v14 on the host network. It owns the `wg0` interface (`10.8.0.1/24`) and a small web UI on port 51821.

## Settings that matter

| Variable | Value | Effect |
| --- | --- | --- |
| `WG_HOST` | `home.example.com` | Endpoint written into client configs. Set it to your dynamic DNS name. |
| `WG_DEFAULT_DNS` | `10.8.0.1` | Clients use the Pi-hole on the Pi, which gives them the internal names and ad blocking. |
| `WG_ALLOWED_IPS` | `0.0.0.0/0` | Full tunnel by default. Edit a client's config for split tunnel. |
| `WG_PERSISTENT_KEEPALIVE` | `25` | Keeps phones behind NAT reachable. |
| `WG_POST_UP` / `WG_POST_DOWN` | `iptables-nft` rules | Masquerade for the tunnel subnet and forwarding on `wg0`. They use `iptables-nft` because newer kernels no longer load legacy xtables. |

The image is pinned to `:14`. Version 15 is a rewrite that drops the `WG_*` variables, so upgrade it deliberately.

## Setup

```bash
cp .env.example .env
```

Generate the password hash for the web UI and put it in `.env` inside single quotes, so compose does not try to expand the `$` characters:

```bash
docker run --rm ghcr.io/wg-easy/wg-easy:14 wgpw 'a-long-password'
```

```bash
docker compose up -d
```

On the router, forward only `51820/udp` to the Pi. The web UI is not forwarded: reach it from the LAN or the tunnel through the `vpn.` vhost (`infra/nginx/sites/internal.conf`).

## Onboarding a peer

1. Open the web UI over the LAN or an existing tunnel and add a client. Name it after the device.
2. Scan the QR code on a phone, or download the `.conf` for a laptop. The config holds that peer's private key: treat it like a password and do not commit or paste it.
3. Check the handshake: `sudo docker exec wg-easy wg show` lists the peer with a recent handshake time.
4. Test the DNS path from the client: `dig vault.home.example.com` should return the Pi's LAN address.

Peer-to-peer traffic inside the tunnel is blocked. The firewall (`infra/hardening/ufw.sh`) denies routed traffic and allows only `eth0` to `wg0` and back, so add a narrow `ufw route allow` rule for any single peer-to-peer service you want.

## Restart caveat

Because wg-easy owns `wg0`, restarting the Docker daemon or force-recreating this container drops every tunnel, including the one you are managing it from. Run the restart detached and reconnect afterwards:

```bash
sudo systemd-run --unit=docker-restart-once --collect sh -c 'systemctl restart docker'
```
