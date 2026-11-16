# DNS: Pi-hole, unbound, split horizon

```
client -> Pi-hole :53 -> unbound 127.0.0.1:5335 -> root servers
```

Pi-hole (`infra/stacks/infra`, host network) answers every client on the LAN and the VPN. Its only upstream is unbound, a recursive resolver on loopback, so queries go to the authoritative servers directly.

## Split horizon

`pihole-dnsmasq/10-home-wildcard.conf` is mounted into Pi-hole's `/etc/dnsmasq.d`:

```
address=/home.example.com/192.168.1.10
```

dnsmasq's `address=` matches the name and every subdomain, so `vault.home.example.com`, `photos.home.example.com` and the rest all resolve to the Pi's LAN address for any client using Pi-hole. VPN clients get the same answer because `WG_DEFAULT_DNS` points at the Pi, and the Pi routes the traffic back through `wg0`.

`99-edns.conf` caps the EDNS packet size at 1232 bytes, the usual guard against UDP fragmentation. `unbound/pi-hole.conf` sets the same `edns-buffer-size`.

## unbound

`unbound/pi-hole.conf` listens on `127.0.0.1:5335`, IPv4 only, with DNSSEC hardening (`harden-glue`, `harden-dnssec-stripped`), prefetching, and `private-address` ranges so a hostile answer cannot point public names at private networks. Install it to `/etc/unbound/unbound.conf.d/`.

## Check

On the Pi, `/etc/hosts` answers the apex name with `127.0.0.1`. Ask the LAN address to see what a client sees:

```bash
dig vault.home.example.com @192.168.1.10
```
