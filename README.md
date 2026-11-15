# Audit Log Hub

A central platform that receives audit logs from every company system over a REST API, stores them **append-only** (no update, no delete), keeps each project's data fully isolated, and provides an Arabic dashboard (Filament 5) for search, export and administration.

**Stack:** Laravel 13 · PHP 8.4 · Filament 5 · MariaDB 11.8 · Redis · Horizon · Node 24 · Docker

---

## Getting started

The only requirement on your machine is **Docker Desktop**.

```bash
./bin/setup
```

The script is safe to re-run. It:
1. Creates `.env` with random passwords.
2. Builds the images and installs dependencies.
3. Generates the backup encryption key (age).
4. Starts the services and runs `migrate --seed`.

To create the first Super Admin, set `ADMIN_EMAIL` (lowercase) and `ADMIN_PASSWORD` in `.env`, then run `make seed`. On first sign-in you are asked to set up two-factor authentication (any Authenticator app).

After that, `docker compose up -d` is all you need.

| URL | Description |
|---|---|
| http://localhost:8080/admin | Dashboard |
| http://localhost:8080/api/v1 | API ([docs](http://localhost:8080/docs/api)) |
| http://localhost:8080/horizon | Queue monitoring (Super Admin) |
| http://localhost:8080/telescope | Telescope (development only) |

### Services

| Service | Role |
|---|---|
| `app` | PHP 8.4-FPM |
| `nginx` | Web server on port 8080 |
| `mariadb` | MariaDB 11.8 with binary logs, on `127.0.0.1:33060` |
| `redis` | Queues, cache, sessions and rate limiting |
| `queue-worker` | Horizon: stores received logs |
| `scheduler` | Scheduled tasks: partitions, archiving, retention, backups, alerts |
| `backup` | Daily full and hourly incremental mariadb-backup, binary logs, age encryption, rclone upload (production), weekly restore test |

> In development the archive and backups stay local (`storage/app/archive`, `storage/app/backups` and the `backups` volume). In production they go to off-server S3 (runbook §1).

### Useful commands

```bash
make help        # all commands
make ps          # service status and health checks
make demo        # demo data: 4 systems and one account per role (development only)
make test        # test suite (audit_testing database)
make lint        # Pint
make analyse     # PHPStan / Larastan level 6
make fresh       # rebuild the database from scratch
./bin/doctor     # full health check, report in storage/logs/doctor.log
make partitions  # audit_logs partitions
make schema-dump # refresh database/schema/schema.sql
```

---

## Connecting a system

1. In the dashboard: **Projects → Create**. A slug and an API key are generated; the key is shown **only once**.
2. Send logs:

```bash
curl -X POST https://audit.example.com/api/v1/logs \
  -H "Authorization: Bearer $AUDIT_API_KEY" -H "X-Project: erp" \
  -H "Content-Type: application/json" \
  -d '{"external_id":"order-1001-paid","event":"order.paid","action":"update",
       "occurred_at":"2026-11-11T10:00:00Z","subject":{"type":"order","id":"1001"},
       "old_values":{"status":"pending"},"new_values":{"status":"paid"}}'
```

3. Laravel projects can use the `company/audit-client` package from its private repository, [SadeenAlharbi/audit-client](https://github.com/SadeenAlharbi/audit-client):

```json
"repositories": [{ "type": "vcs", "url": "git@github.com:SadeenAlharbi/audit-client.git" }]
```

```bash
composer require company/audit-client:^1.0
```

Full details: [docs/integration-guide.md](docs/integration-guide.md). API contract: [docs/openapi.yaml](docs/openapi.yaml).

---

## How it works

- **Ingestion:** `POST /api/v1/logs` (or `/logs/batch`, up to 500) is authenticated by a hashed API key, optionally HMAC-signed, validated, de-duplicated by `external_id`, answered with `202`, and stored by a queue worker.
- **Immutability:** database triggers reject every `UPDATE` and `DELETE` on `audit_logs`; only the archiver account may delete, and only after the data is archived.
- **Isolation:** every query is scoped to the projects the user or API key may access.
- **Sensitive data:** credentials (passwords, tokens, card numbers) are removed before storage; personal data is masked on display unless the viewer has the "view sensitive data" permission.
- **Retention:** monthly partitions; logs are archived as compressed JSONL, kept for a grace period, then deleted according to each project's retention.
- **Backups:** daily full, hourly incremental and binary logs (RPO 1 hour), encrypted, copied off-site, with a weekly automated restore test.
- **Dashboard roles:** Super Admin, Platform Auditor, Security Officer, Project Owner, Project Viewer, plus custom roles. Two-factor authentication is mandatory for platform-wide roles.

---

## Documentation

| Document | Contents |
|---|---|
| [docs/integration-guide.md](docs/integration-guide.md) | Integration guide: authentication, signing, examples (cURL / PHP / Node / Python / Laravel), errors |
| [docs/openapi.yaml](docs/openapi.yaml) | API contract (OpenAPI 3.1), served at `/docs/api` |
| [docs/runbook.md](docs/runbook.md) | Operations: deployment, backups, full and per-project restore, key rotation, incidents |
| [docs/permissions-matrix.md](docs/permissions-matrix.md) | Roles and permissions matrix (covered by automated tests) |
| [docs/schema.md](docs/schema.md) · [database/schema/schema.sql](database/schema/schema.sql) | Schema and ERD |
| [docs/decisions.md](docs/decisions.md) | Technical decisions and their reasons |
| [docs/security-review.md](docs/security-review.md) | Security review |
| [docs/plan-compliance.md](docs/plan-compliance.md) | Every requirement of the plan, where it is implemented and how it is tested |
| [docs/product/product-overview.pdf](docs/product/product-overview.pdf) | Product overview: purpose, roles, the life of a log, screens |
| [docs/reports/](docs/reports/) | Restore test reports |

Most documents under `docs/` are written in Arabic.

---

## Quality

- **Pest:** 194 tests against a real MariaDB (partitioning and triggers need the real engine).
- **Coverage:** at least 80% for the API layer, enforced in CI by `bin/coverage-api`.
- **Pint + PHPStan level 6:** no errors.
- **CI:** [.github/workflows/ci.yml](.github/workflows/ci.yml) runs every check inside Docker, builds the production images and lints the OpenAPI file.

## Security

- No secrets in the repository: `.env` and `secrets/` (the private age key) are git-ignored.
- In production set `APP_ENV=production` and keep HTTPS enforced (`AUDIT_API_REQUIRE_HTTPS`). See [docs/runbook.md](docs/runbook.md).

## Migration policy

- One migration per table, containing all its columns, indexes, constraints and triggers.
- **Before the first deployment:** edit the table's original migration and run `make fresh`; no "alter" migrations.
- **After the first deployment:** only additional, reversible migrations with a tested `down()`.
