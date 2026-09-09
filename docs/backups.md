# Backups & disaster recovery

The panel's durable state lives in exactly three places. A backup plan that
covers all three can rebuild the entire platform on a fresh host:

| State | Where | Contains | RPO target |
|---|---|---|---|
| MySQL | `mysql_data` volume (or managed DB) | wallets, ledger, orders, users, RBAC, audit — **the money** | ≤ 15 min (binlog PITR) |
| Object storage | `STORAGE_BUCKET` | uploads, gift card attachments, media | 24 h |
| Secrets/config | `.env` + `ENCRYPTION_KEY` | without the key, encrypted provider credentials and MFA secrets are unrecoverable | on every change |

Everything else (code, Docker images, cron schedules) is reproducible from
this repository.

## 1. Nightly logical dumps

```bash
# /opt/marvy/bin/backup-db.sh — cron: 0 2 * * *
#!/bin/sh
set -eu
ts=$(date +%Y%m%d-%H%M%S)
docker compose -f docker-compose.production.yml exec -T mysql \
  mysqldump -u root -p"$MYSQL_ROOT_PASSWORD" \
    --single-transaction --quick --routines --triggers --events \
    marvysocials | gzip > "/backups/db/marvysocials-$ts.sql.gz"
find /backups/db -name 'marvysocials-*.sql.gz' -mtime +14 -delete
```

`--single-transaction` keeps the dump consistent without blocking writes —
the ledger is append-mostly, so a dump taken mid-order stays consistent.

## 2. Point-in-time recovery (binlog)

The production compose starts MySQL with `--binlog_expire_logs_seconds=604800`
(7 days of binlogs). Between nightly dumps, replay binlogs to reach any
second:

```bash
docker compose -f docker-compose.production.yml exec -T mysql \
  mysqlbinlog --start-datetime="2026-08-19 02:00:00" \
  /var/lib/mysql/binlog.00000N | mysql -u root -p marvysocials
```

Ship binlogs off-box hourly (`mysqlbinlog --read-from-remote-server`) if the
RPO is tighter than 24 h. Managed RDS/Aurora/CloudSQL: enable automated
backups + PITR and skip this section.

## 3. Object storage

```bash
aws s3 sync s3://marvysocials-prod s3://marvysocials-backup/prod/ \
  --storage-class GLACIER_IR
```

or enable cross-region replication + versioning on the bucket. Uploads are
content-addressed and never mutated in place, so sync-based backup is exact.

## 4. Secrets

`ENCRYPTION_KEY` deserves the strictest storage you have (1Password/SSM/
Vault): losing it makes every encrypted credential and MFA secret in the
database unreadable **even with a perfect database backup**. Store it apart
from the host it encrypts.

## 5. Restore rehearsal (do this, then schedule it quarterly)

1. Fresh host: `git clone`, `cp .env.production.example .env`, fill values.
2. `mysql < marvysocials-<ts>.sql.gz` into a clean database.
3. `php index.php migrate` — catches schema drift between dump and code.
4. `php index.php deploy check` — must exit 0.
5. `curl -fsS localhost:8080/health/ready` — must answer `ready`.
6. Log in as a staff account, open an order, confirm wallet balances match
   the platform totals report (Admin → Analytics).
7. Restore object storage (`aws s3 sync` the backup bucket back) and load a
   listing image.

Time the rehearsal; the number is your real RTO, and it goes in the runbook
header for the next incident.

## 6. Failure playbooks

| Symptom | First action |
|---|---|
| `/health/ready` 503, `checks.database: fail` | Check `docker compose ps` — mysql unhealthy ⇒ `docker compose logs mysql`. Do not restart the app first. |
| `checks.schema: fail` after a deploy | Run `php index.php migrate`, confirm `migrate status` is clean, readiness flips green on its own. |
| Cron jobs stopped (`cron.log` silent) | `docker compose ps` — the `cron` container is down. `php index.php cron status` shows the backlog; jobs are idempotent and pick up on next tick. |
| Provider calls all failing | Admin → Providers → health tab; check outbound egress (SSP/SSL) and the provider balance. |

## 7. What is NOT backed up

- Redis — cache, rate-limit counters and session rows are disposable by
  design; a Redis flush logs users out and re-warms caches. Persistent money
  never lives in Redis.
- `storage/logs` — shipped to a log aggregator in production; losing them is
  an observability gap, not data loss.
