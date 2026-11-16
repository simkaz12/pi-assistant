# Docker stacks

Three compose projects. Copy `.env.example` to `.env` next to each compose file and replace every `changeme`.

| Stack | Containers | Host ports |
| --- | --- | --- |
| `infra` | Pi-hole (host network), wg-easy (host network), Jellyfin | 53, 8053, 51820/udp, 51821, `127.0.0.1:8096` |
| `apps` | Diun, Vaultwarden, Seerr, Paperless-ngx (with Redis, Tika, Gotenberg), Immich (server, machine learning, Valkey, Postgres) | `127.0.0.1` only: 8222, 5055, 8010, 2283 |
| `ai` | Ollama | `127.0.0.1:11434` |

Application containers publish on loopback. nginx (`infra/nginx`) proxies the internal names to them, which keeps authentication and the LAN/VPN allow-list in one place. Data lives under `/mnt/media/apps/<name>`.

After the first start of the `ai` stack, pull the embedding model the memory server uses:

```bash
docker exec pi-ollama ollama pull nomic-embed-text
```

One-time setup in the apps: Seerr's wizard needs the Jellyfin URL, Immich needs an admin account on first visit, and Vaultwarden ships with `SIGNUPS_ALLOWED: "false"`. Set it to `"true"` for the first start, create the owner account, then set it back and run `docker compose up -d vaultwarden`.

Validate a file without starting anything:

```bash
cp .env.example .env && docker compose config -q
```
