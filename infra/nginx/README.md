# nginx

| File | Install to | What |
| --- | --- | --- |
| `rate-limits.conf` | `http` context (for example `conf.d/`) | `limit_req` and `limit_conn` zones used by the app vhost |
| `snippets/internal-only.conf` | `/etc/nginx/snippets/` | Wildcard cert, TLS settings, the LAN/VPN allow-list, and the 403 to 444 rewrite |
| `sites/internal.conf` | `sites-enabled/` | One vhost per internal name, each proxying to a loopback port and including the snippet |
| `sites/app.conf` | `sites-enabled/` | The admin app vhost (php-fpm) |
| `reverb.conf` | included from `app.conf` | Websocket proxy to Reverb on `127.0.0.1:6001` |
| `reject-unknown-host.conf` | `conf.d/`, loaded after the sites | Default server that rejects the TLS handshake for any name not served |

## The 444 pattern

An internal vhost lists allowed networks (`127.0.0.0/8`, `10.8.0.0/24`, `192.168.1.0/24`) and ends with `deny all`. `error_page 403 =444 /444` turns the denial into nginx's non-standard 444, which closes the connection with no response. A scanner on the internet learns nothing: no 403 page, no server banner. Separately, `reject-unknown-host.conf` makes TLS fail for names nginx does not serve, so probing by IP gets no certificate either.

`deny 192.168.1.1` comes first on purpose. nginx takes the first matching rule, so the router address is refused even though it sits inside the allowed LAN range.

## Reverb

Browsers connect to `wss://<app host>/ws/...` on the page's own origin. nginx proxies `/ws/` to Reverb on loopback, with the `Upgrade` headers, and a 120 second read timeout. Reverb listens on 6001 because 8080 may be taken by another service.
