# Local Docker development with host services

Use this setup when Lerd, Laravel Herd, Valet, or another local environment already provides your web server, MySQL, or Redis. It starts only OGameX PHP services in Docker and does not bind ports 80, 443, 3306, or 8080.

There is only one environment file to maintain: the repository root `.env`.
Compose reads its `LOCAL_DB_*` and `LOCAL_REDIS_*` values directly and injects
the resolved `DB_*` and `REDIS_*` values into every container. This includes
commands launched with `docker exec`, such as the parallel test suite. Set
`LOCAL_DB_DATABASE` to a dedicated development or test database, and add
`LOCAL_DB_HOST`, `LOCAL_DB_PORT`, `LOCAL_DB_USERNAME`, or `LOCAL_DB_PASSWORD`
only when your host services differ from the defaults.

Build the image once from the repository root, then start the app and scheduler:

```bash
docker build -f local-docker-dev/Dockerfile -t ogamex-local-docker-dev:latest .
docker compose -f local-docker-dev/docker-compose.yml up -d
```

The app publishes PHP-FPM on port 9000. Configure your existing web server to pass PHP requests to `127.0.0.1:9000`. The containers reach host MySQL and Redis at `host.docker.internal`; the Linux `host-gateway` mapping is included in the Compose file.

Reverb starts with the core stack and serves secure WebSockets on port 8090 using
Yerd's local certificate. This is required when the site is served at an HTTPS
`.test` URL. Set the root `.env` client values to:

```dotenv
REVERB_HOST=ogamex-next.test
REVERB_PORT=8090
REVERB_SCHEME=https
```

The app container still broadcasts to Reverb over the private Docker network.
The queue worker is opt-in:

```bash
docker compose -f local-docker-dev/docker-compose.yml --profile queue up -d
```

### Laravel Horizon

The queue worker can run Laravel Horizon instead of the database worker pools. Set
`QUEUE_CONNECTION=redis` in the root `.env` and start the same `queue` profile; the
container detects the driver and starts Horizon, and Compose recreates the other
containers with the new value. Redis is reached through the host service configured
by `LOCAL_REDIS_*`.

```bash
docker compose -f local-docker-dev/docker-compose.yml --profile queue up -d
```

The dashboard is served at `/admin/horizon` and is limited to users with the
`admin` role. Worker pools, queue names and their timeouts live in
`config/horizon.php` and use the enum in `app/Enums/QueueName.php`. Pool sizes and
worker limits have per-environment defaults and can be tuned from `.env` with the
`HORIZON_*` variables listed in `.env.example`. Enabled modules may contribute
their own Horizon lanes at runtime. Rebuild the
image once (`docker build -f local-docker-dev/Dockerfile -t ogamex-local-docker-dev:latest .`)
so the phpredis extension required by Horizon is installed.

## Cohorts in a browser

The cohort stacks publish PHP-FPM only, so they have no web server of their own. YERD already serves
this checkout on ports 80/443, so the cohorts are two more YERD-served names whose PHP goes to the
cohort's own pool rather than to YERD's PHP:

| Name | PHP runs in | Project |
| --- | --- | --- |
| `https://ogame-module-grand.test` | `127.0.0.1:9001` | `ogamex-grand` |
| `https://ogame-module-pve.test` | `127.0.0.1:9002` | `ogamex-pve` |

YERD keys sites by directory and this repository is already registered, so the two blocks live in their
own file — `/opt/yerd/web/nginx/sites-enabled/ogame-module-cohorts.conf` — where a later
`yerd sites …` regeneration cannot overwrite them. Their certificates are signed by YERD's own CA in
`/opt/yerd/web/certs/ca`, so the browser trusts them exactly like the other `.test` sites.

The paths line up because every app container mounts this checkout at `/var/www`: nginx serves the
static files from the checkout, while the container's pool executes `/var/www/public/index.php` with
that cohort's own database, Redis prefix and AI settings.

To recreate the setup — after a reinstall, a `wsl --shutdown`, or a YERD upgrade:

```bash
# 1. Certificates, signed by YERD's CA.
cd /opt/yerd/web/certs/sites
for n in ogame-module-grand.test ogame-module-pve.test; do
  openssl req -new -newkey rsa:2048 -nodes -keyout "$n.key" -out "/tmp/$n.csr" -subj "/C=GB/CN=$n"
  openssl x509 -req -in "/tmp/$n.csr" -CA /opt/yerd/web/certs/ca/yerd.crt \
    -CAkey /opt/yerd/web/certs/ca/yerd.key -CAserial /opt/yerd/web/certs/ca/yerd.srl \
    -out "$n.crt" -days 825 -sha256 -extfile <(printf "subjectAltName=DNS:%s" "$n")
done

# 2. Names in the Windows hosts file, through YERD's own helper.
powershell.exe -File 'C:\ProgramData\YerdWindowsHosts\request.ps1' -Action add -Domain ogame-module-grand.test
powershell.exe -File 'C:\ProgramData\YerdWindowsHosts\request.ps1' -Action add -Domain ogame-module-pve.test

# 3. Load the site file.
sudo systemctl reload yerd-nginx
```

Admin logins: grand `Legor`, pve `Admin`.

Keep `LOCAL_DB_DATABASE` pointed at a dedicated development or test database. This setup runs migrations when the app container starts.

## When "every command hangs"

A wedged WSL host transport looks like a slow database and is not one. The symptoms are: container
commands that touch MySQL or Redis stall, every call into `/mnt/c` or a Windows `.exe` fails with
`UtilAcceptVsock: accept4 failed 110`, existing shells keep working, and `docker logs` still looks alive.

**The cause is a hung Hyper-V channel open in the guest kernel, and it is a known, unfixed WSL bug.**
Our own frozen boot shows it exactly:

```
INFO: task kworker/0:3 blocked for more than 2174 seconds
Workqueue: hv_pri_chan vmbus_add_channel_work
 __wait_for_common ← wait_for_completion ← __vmbus_open ← vmbus_open ← hvs_probe
```

The workqueue thread waits forever for a channel open to complete, and that channel is what carries Windows
interop **and** `/mnt/c` (9p), so both die at once. Nothing inside the guest can clear a kernel thread stuck
in `D` state. Two upstream issues describe this same stack, and both are still open:

- [microsoft/WSL#40795](https://github.com/microsoft/WSL/issues/40795) — `vmbus_alloc_ring()` needs a
  **contiguous 512 KiB (order-7)** allocation; under memory pressure orders 7–10 are exhausted *while
  gigabytes of lower-order memory are free*, the channel open then never completes, and session creation
  hangs. The proposed fix (fall back to `vzalloc`, plus balloon backpressure) is
  [microsoft/WSL#41634](https://github.com/microsoft/WSL/issues/41634) / PR `#40519` — **not merged**.
- [microsoft/WSL#40650](https://github.com/microsoft/WSL/issues/40650) — `accept4 failed 110` when the
  **vsock connection count is high**, with no tunable limit.

Which of the two fires first here is not distinguishable from the logs, and neither is cause for a reboot-only
recovery in principle: `wsl --shutdown` should end the VM. It sometimes hangs too
([microsoft/WSL#14005](https://github.com/microsoft/WSL/issues/14005)), and that issue documents the one
detail worth remembering:

> **Use `Restart`, not `Shut down`.** Fast startup preserves the broken state, so a shutdown-and-power-on
does not recover it.

Recovery order: `wsl --shutdown` → if that hangs, restart the Host Network Service
(`Restart-Service hns` as Administrator) and retry → only then restart Windows. Start Docker Desktop again
afterwards, and bring the cohorts back with `up -d` (WSL restart kills them, and they may exit 127 until
re-created).

### The real fix: an orphaned-Relay leak, not the allocator

The fragmentation has a driver, and it is documented and fixed upstream — **microsoft/WSL#41242**. In
`CreateProcessUtilityVm` the relay thread calls `UtilAcceptVsock()` with no timeout, so when wslservice
completes only some of a session's socket connects the relay blocks **forever**: it never exits, ignores
`SIGTERM`, and keeps its hv_sock channels open — each channel pinning an order-7 (512 KiB physically
contiguous) `vmbus_alloc_ring` allocation ([#40795](https://github.com/microsoft/WSL/issues/40795)).
Enough stuck relays and the guest cannot allocate a new ring: new sessions get `accept4 failed 110`, the
`hv_pri_chan` kworker sits in `__vmbus_open` (exactly the stack in our frozen boot), and `/mnt/c` plus
interop die with it while existing shells keep working. Accumulation is boot-scoped — one field report had
194 orphans, 119k "abnormally long accept" records and 277 order-7 failures; a clean-boot reproduction saw
the first order-7 failure at T+24 min and 36 orphans by T+6.5 h.

- **Fix:** PR **[#41252](https://github.com/microsoft/WSL/pull/41252)** ("Add timeout to create process
  accepts", merged, label `fixinbound`) ships in **WSL 2.9.8**. This machine is on **2.7.13.0 → still
  vulnerable**; the clean fix is `wsl --update --pre-release`.
- **Guard meanwhile:** `sudo ~/.local/bin/wsl-relay-gc` reports, `--kill` reaps. The relays are
  **root-owned** so it always needs root, and they ignore `SIGTERM`, hence `SIGKILL`. Detector: comm
  exactly `Relay` — live relays are named `Relay(<childpid>)`.
- **This retires the sysctl theory below:** `compact_memory` cannot recover a wedged VM (the pinned rings
  are unmovable), and #41286 cleared `autoMemoryReclaim`/`sparseVhd` with evidence. Treat the guest knobs
  as second-order hygiene, not the cure.

### Second-order: guest allocator hygiene

High-order fragmentation is still worth reducing in the guest. `vm.compaction_proactiveness` was `0`,
which disables the proactive compaction that keeps 512 KiB blocks available:

```bash
sudo tee /etc/sysctl.d/99-wsl-vmbus-fragmentation.conf >/dev/null <<'EOF'
# Keep order-7 blocks available for vmbus_alloc_ring (microsoft/WSL#40795, #41634).
vm.min_free_kbytes = 262144
vm.compaction_proactiveness = 20
vm.extfrag_threshold = 200
EOF
sudo sysctl --system
```

Applied 18 Sep 2026 on this machine (backups: `.wslconfig.bak-preworkload-*`, `.wslconfig.bak-clocksource-*`):

- **`memory` left at the owner's `20GB`** on a 64 GB host — a raise to 32 GB was tried and reverted, so do
  not change it. Memory pressure is what fragments the allocator, and the guest also runs MySQL, Redis,
  Horizon, two 1000× cohorts and 8+ containers.
- **`vmIdleTimeout=-1`** — every VM teardown/restart is another round of channel opens (and another reason
  the cohorts need `up -d`). Trade: the VM keeps its memory while idle; `autoMemoryReclaim=gradual` still
  returns page cache.
- **Guest sysctls** in `/etc/sysctl.d/99-wsl-vmbus-fragmentation.conf` (below) — the order-7 headroom itself,
  **except the `compaction_proactiveness` line**: after `sysctl --system` (and after a full WSL restart),
  `/proc/sys/vm/compaction_proactiveness` still reads `0` while the other two keys do apply. `systemd-sysctl`
  logs **no** error for it and no other sysctl file sets that key. `docker-desktop` is a second,
  concurrently-booting distro on the same kernel, so a writer there is the prime suspect. Until that is
  settled, treat `min_free_kbytes` + `extfrag_threshold` as the only active half of the mitigation. A root
  write followed by a re-read settles whether it can be held at all:
  `sudo sysctl -w vm.compaction_proactiveness=20; sleep 5; sysctl vm.compaction_proactiveness`.

And `wsl-relay-guard` catches the interop failure in minutes rather than after a 36-minute hang.

It is a stuck vmbus channel in the guest kernel — Windows interop, `/mnt/c` and Docker Desktop's
integration all ride that one transport — so nothing inside WSL can clear it. From a Windows terminal:

```powershell
wsl --shutdown
```

Then start Docker Desktop again.

## Separately: the clock churn, and why it is *not* the freeze

Do not diagnose these as one problem — this cost real time once already. `systemd-resolved` logging
`Clock change detected. Flushing caches.` every ~30 s is a **different, unrelated** pair of open upstream
bugs. It makes the clock jumpy; it does not wedge the transport.

- **microsoft/WSL#12583 — "CLOCK_MONOTONIC frequency inaccurate"** (open since Feb 2025): the guest
  monotonic clock runs at the wrong rate. Measured here: `sleep 60` spans **63 s of real time** (~4 % slow);
  a reporter on the *same* kernel (6.18.33.2) measured ~7 % slow. **The clocksource is not the cause** —
  that reporter tested `tsc` and `hyperv_clocksource_tsc_page` with identical results, and pinning
  `hyperv_clocksource_msr` here changed nothing (the change was reverted).
- **microsoft/WSL#13777 — the "Clock change detected" loop** (open): Docker Desktop's `initd` calls
  `settimeofday` inside the shared utility VM every 30 s, and because all distros share one
  `CLOCK_REALTIME`, every distro sees it. Quitting Docker Desktop stops those. Filed as
  `docker/for-win#15000`; systemd declined it (`systemd#43028`).

The reporters' mitigation is to leave one authority standing — stop the guest's NTP after making the host
clock trustworthy (`net start w32time`, `w32tm /resync /force`, then
`sudo systemctl disable --now systemd-timesyncd`). The trade: after a host suspend the guest no longer
corrects itself, so run `sudo hwclock -s` when it drifts.

Until #12583 is fixed, treat any duration measured inside WSL as ~4 % off.
