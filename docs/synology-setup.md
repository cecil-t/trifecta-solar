# Running the tracker on the Synology DS225+

The app runs as one Docker container under **Container Manager**. The code lives in a git clone at
`/volume1/docker/trifecta-solar`. The live SQLite database is in a Docker-managed volume named `trifecta-data`.
Synology shared folders use DSM ACLs that stop the container's web user from writing, which is why the
database isn't in the share. Nightly verified snapshots go to `backups/` in the share, where Hyper Backup can reach them.

```
/volume1/docker/trifecta-solar/
├── backups/trifecta-*.sqlite <- verified nightly snapshots (this is what gets backed up offsite)
└── ... code (git) ...

Docker volume "trifecta-data"  <- live database (never copy it while running; use backups/)
```

---

## 1. One-time NAS prep

1. **Package Center**: install **Container Manager**. This also creates the `docker` shared folder.
2. **Package Center**: install **Git Server**. Its only purpose here is to provide the `git` command.
3. **Control Panel → Terminal & SNMP**: tick **Enable SSH service** and click Apply.
   You can turn SSH back off between updates if you prefer.

## 2. Give the NAS read-only access to the private repo (deploy key)

SSH in with your DSM admin account, then become root:

```sh
ssh <your-dsm-user>@<nas-ip>
sudo -i
```

Create a key that only this NAS uses, and point git at it:

```sh
mkdir -p /root/.ssh && chmod 700 /root/.ssh
ssh-keygen -t ed25519 -C "ds225-trifecta" -f /root/.ssh/trifecta_deploy -N ""
cat >> /root/.ssh/config <<'EOF'
Host github.com
  IdentityFile /root/.ssh/trifecta_deploy
  IdentitiesOnly yes
EOF
chmod 600 /root/.ssh/config
cat /root/.ssh/trifecta_deploy.pub
```

Copy the printed `ssh-ed25519 ...` line. On GitHub, go to **cecil-t/trifecta-solar → Settings → Deploy keys → Add deploy key**,
give it the title `DS225+`, paste the line, and leave **Allow write access unchecked**.

Test the connection (answer `yes` to the host fingerprint prompt):

```sh
ssh -T git@github.com
# expect: "Hi cecil-t/trifecta-solar! You've successfully authenticated..."
```

## 3. Clone

```sh
cd /volume1/docker
git clone git@github.com:cecil-t/trifecta-solar.git
```

## 4. Create and start the container

**Container Manager → Project → Create**

| Field | Value |
|---|---|
| Project name | `trifecta-solar` |
| Path | `/docker/trifecta-solar` |
| Source | **Use existing docker-compose.yml** |

Click Next. Leave the Web Station / web portal option **unchecked**, then click Next and Done.
Container Manager then builds the image, which takes a few minutes the first time, and starts the container.

(The command-line equivalent is `cd /volume1/docker/trifecta-solar && docker compose up -d --build`.
A project created this way still runs fine, but it won't show up under Container Manager's *Project* tab.)

## 5. First login

Browse to **http://&lt;nas-ip&gt;:8089**. The app opens on the first-time setup page:

1. Choose your admin account and set its password. You are then signed in.
2. Under **Users**, set a temporary password for each person and send it to them privately.
   Each person can change it under **My account**.

Port 8089 can be changed in `docker-compose.yml` (the left side of `"8089:80"`).

## 6. Nightly backups (do this right away)

**Control Panel → Task Scheduler → Create → Scheduled Task → User-defined script**

- General: name `Trifecta tracker backup`, user **root**
- Schedule: Daily, 2:00 AM
- Task Settings: tick **Send run details by email → only when the script terminates abnormally**
  (this requires DSM email notifications to be configured). Run command:

```sh
/usr/local/bin/docker exec trifecta-solar php bin/console backup >> /volume1/docker/trifecta-solar/backups/backup.log 2>&1
```

Each run writes `backups/trifecta-YYYYMMDD-HHMMSS.sqlite` and verifies it with an integrity check.
It keeps the newest 30 snapshots.

**Offsite copy:** in **Hyper Backup**, add `docker/trifecta-solar/backups` to a backup task with an
offsite destination (Synology C2, Backblaze B2, Google Drive, and so on). These snapshots are always consistent,
unlike a file copy of a live database.

To run a backup by hand: `docker exec trifecta-solar php bin/console backup`

**Restore** (replace FILE with the snapshot name):

```sh
cd /volume1/docker/trifecta-solar
docker compose stop
docker run --rm --entrypoint sh -v trifecta-data:/data -v "$PWD/backups":/b trifecta-solar:latest -c \
  'cp /b/FILE /data/trifecta.sqlite && rm -f /data/trifecta.sqlite-wal /data/trifecta.sqlite-shm && chown 33:33 /data/trifecta.sqlite'
docker compose start
```

## 7. Updating

Most updates are code only:

```sh
sudo -i
cd /volume1/docker/trifecta-solar && git pull
```

That's it. The repo folder is mounted into the container, so new code is live immediately, and any new
database migrations apply automatically on the next page load.

If an update touches `Dockerfile`, `docker-compose.yml`, or `docker/`, also rebuild:
**Container Manager → Project → trifecta-solar → Action → Build**, or run `docker compose up -d --build`.

## Useful commands

```sh
docker logs -f trifecta-solar                               # Apache/PHP logs
docker exec trifecta-solar php bin/console user:list        # users and password status
docker exec -it trifecta-solar php bin/console user:password person@example.com   # emergency password reset
curl -s http://localhost:8089/health                        # {"status":"ok",...}
```

## Importing projects

The import file (`import.json`) holds customer data, so it is never committed to git. Put it in
`/volume1/docker/trifecta-solar/import/` (git ignores that folder), then:

```sh
docker exec trifecta-solar php bin/console import:projects import/import.json --dry-run   # report only, saves nothing
docker exec trifecta-solar php bin/console import:projects import/import.json             # load it
```

Re-running skips project numbers that already exist. `--replace` deletes and re-imports those projects
(their tasks and log too), which is meant for re-running an improved import before real work is entered.

## Notes

- **Installing to phone home screens (PWA)** requires HTTPS, which comes with the public hostname step below.
  Over plain `http://<nas-ip>:8089` the site works in a mobile browser but can't be installed.
- **Remote access / HTTPS (later):** the plan is a Cloudflare Tunnel or the DSM reverse proxy with a
  certificate on a hostname such as `tracker.trifectasolar.com`. When that is in place, create `.env` with `TRUST_PROXY=true`.
- Logins never expire on their own. To cut off a lost phone, sign that device out under **Users → (person)**,
  or under **My account** for your own devices.
