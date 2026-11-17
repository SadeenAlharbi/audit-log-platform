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
| `backup` | Daily full and hourly incremental mariadb-backup, binary logs, age encryption, rclone upload with byte-for-byte check, weekly restore test |
| `keycloak` | Development SAML identity provider, only with `make sso` (profile `sso`) |

> In development the archive stays local (`storage/app/archive`) and the encrypted backups go to the `offsite-sim` volume, which stands in for off-site storage (`BACKUP_RCLONE_REMOTE=/offsite`). In production both go to off-server S3 (runbook §1).

### Useful commands

```bash
make help        # all commands
make ps          # service status and health checks
make demo        # demo data: 4 systems, one account per role; one-click sign-in list on the login page (local only, no 2FA)
make test        # test suite (audit_testing database)
make lint        # Pint
make analyse     # PHPStan / Larastan level 6
make openapi-lint # lint docs/openapi.yaml in the Node 24 image (node:24-alpine)
make fresh       # rebuild the database from scratch
./bin/doctor     # full health check, report in storage/logs/doctor.log
make sso         # SAML single sign-on with a local Keycloak (SAML2_ENABLED=true first)
make offsite-drill # restore drill from the off-site copy with the private key
make partitions  # audit_logs partitions
make schema-dump # refresh database/schema/schema.sql, then ./bin/erd for the ERD (needs Graphviz)
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
- **Retention:** monthly partitions; logs are archived daily as compressed JSONL (one file per project per day), kept for a grace period, then deleted according to each project's retention.
- **Backups:** daily full, hourly incremental and binary logs (RPO 1 hour), encrypted, copied off-site and checked byte for byte, with a weekly automated restore test and a quarterly drill from the off-site copy (`make offsite-drill`).
- **Self-audit:** sign-ins, role and key changes, exports, backups and every change of the `.env` settings (`audit:config-check`) are recorded in the immutable `platform_audit` table.
- **Dashboard roles:** Super Admin, Platform Auditor, Security Officer, Project Owner, Project Viewer, plus custom roles. Two-factor authentication is mandatory for platform-wide roles.
- **Single sign-on (optional):** SAML 2.0 next to e-mail and password (`scaler-tech/laravel-saml2`). Only existing, active accounts can sign in, matched by e-mail, and the platform's 2FA is always asked after SAML. Setup: [docs/runbook.md §1.6](docs/runbook.md); locally, `make demo` then `make sso` starts Keycloak with demo users.

---

## Documentation

| Document | Contents |
|---|---|
| [docs/integration-guide.md](docs/integration-guide.md) | Integration guide: authentication, signing, examples (cURL / PHP / Node / Python / Laravel), errors |
| [docs/openapi.yaml](docs/openapi.yaml) | API contract (OpenAPI 3.1), served at `/docs/api` |
| [docs/runbook.md](docs/runbook.md) | Operations: deployment, backups, full and per-project restore, key rotation, incidents |
| [docs/permissions-matrix.md](docs/permissions-matrix.md) | Roles and permissions matrix (covered by automated tests) |
| [docs/schema.md](docs/schema.md) · [database/schema/schema.sql](database/schema/schema.sql) · [docs/erd/erd.pdf](docs/erd/erd.pdf) | Schema, ERD (regenerate with `./bin/erd`) |
| [docs/decisions.md](docs/decisions.md) | Technical decisions and their reasons |
| [docs/security-review.md](docs/security-review.md) | Security review |
| [docs/plan-compliance.md](docs/plan-compliance.md) | Every requirement of the plan, where it is implemented and how it is tested |
| [docs/acceptance-testing.md](docs/acceptance-testing.md) | Manual acceptance test of every requirement from the dashboard (and the API with curl) |
| [docs/product/product-overview.pdf](docs/product/product-overview.pdf) | Product overview: purpose, roles, the life of a log, screens |
| [docs/product/product-overview-template.html](docs/product/product-overview-template.html) | The same document as one editable file (Chrome/Edge: edit in place, Ctrl+P to PDF). Source: `docs/product/source/product-overview.html`, rebuilt with `make screenshots` (new screenshots) and `make product-doc` (PDF + template) |
| [docs/reports/](docs/reports/) | Restore test, off-site drill and coverage reports |

Most documents under `docs/` are written in Arabic.

---

## Quality

- **Pest:** 222 tests against a real MariaDB (partitioning and triggers need the real engine), including SAML sign-ins with real signed responses.
- **Coverage:** at least 80% for the API layer, enforced in CI by `bin/coverage-api` (95.3% on 2026-11-16, [report](docs/reports/coverage-2026-11-16.md)).
- **Pint + PHPStan level 6:** no errors.
- **CI:** [.github/workflows/ci.yml](.github/workflows/ci.yml) runs every check inside Docker, builds the production images and lints the OpenAPI file.

## Security

- No secrets in the repository: `.env` and `secrets/` (the private age key) are git-ignored.
- In production set `APP_ENV=production` and keep HTTPS enforced (`AUDIT_API_REQUIRE_HTTPS`). See [docs/runbook.md](docs/runbook.md).

## Migration policy

- One migration per table, containing all its columns, indexes, constraints and triggers.
- **Before the first deployment:** edit the table's original migration and run `make fresh`; no "alter" migrations.
- **After the first deployment:** only additional, reversible migrations with a tested `down()`.

---

## Handover notes

**Status (2026-11-17):** every requirement of the plan (v1.1) is implemented; two are met by a documented equivalent (decisions D7 and D19). The schema and decisions D1–D19 are approved; D20 sets the severity criteria the plan leaves open, D21 how every failed API attempt is recorded, D22 the dashboard time zone. Details: [docs/plan-compliance.md](docs/plan-compliance.md).

### Where things are

| Area | Code |
|---|---|
| API: routes, middleware (key, signature, HTTPS, limits) | `routes/api.php`, `app/Http/Middleware/Api/`, `app/Http/Controllers/Api/V1/` |
| Ingestion: validation, idempotency, queue, storage job | `app/Services/Ingestion/`, `app/Jobs/StoreAuditLogs.php` |
| Masking | `app/Services/Security/SensitiveDataMasker.php`, `config/audit.php` (`masking`) |
| Archive, retention, partitions | `app/Services/Archive/`, `app/Console/Commands/` |
| Backups (app side / container side) | `app/Services/Backup/`, `docker/backup/backup.sh` |
| Dashboard (Filament 5) | `app/Filament/` (Arabic labels: `app/Filament/Support/Labels.php`) |
| Sign-in, 2FA, SAML | `app/Filament/Pages/Auth/`, `app/Filament/Auth/`, `app/Listeners/HandleSamlSignIn.php`, `config/saml2.php` |
| Permissions | `app/Policies/`, `database/seeders/RolesAndPermissionsSeeder.php`, `docs/permissions-matrix.md` |
| Settings | `config/audit.php`, `.env.docker.example` (every variable, with comments) |
| Scheduled tasks | `routes/console.php` |

### Conventions

- Code, comments and commit messages in English; the dashboard and most documents in Arabic.
- Every change comes with tests; `./bin/doctor` must end without errors before a push (tests, Pint, PHPStan, clock and off-site checks).
- Decisions that change what the plan asks for go in `docs/decisions.md` (next number D23).
- `app/Filament/Auth/SetUpAppAuthenticationAction.php` is a copy of a Filament class with two changes (see its header): re-check it when upgrading Filament.

### Before production

1. Fill the production values of runbook §1.2 (`APP_ENV=production`, real S3 bucket for `AUDIT_ARCHIVE_*` and `BACKUP_RCLONE_REMOTE=offsite:<bucket>`, mail and Slack alerts, `TRUSTED_PROXIES`).
2. Keep the private backup key (`secrets/backup-age-identity.txt`) off the server, in a password manager or vault.
3. Run `make offsite-drill` once against the real bucket and keep the report in `docs/reports/`.
4. Optional: connect the company identity provider (runbook §1.6).

### Known limits

- Free-text values are not scanned for personal data; only known keys are masked ([docs/security-review.md](docs/security-review.md)).
- Storage, the API, exports and the archive are in UTC; only the dashboard shows local time (`AUDIT_DISPLAY_TIMEZONE`, default `Asia/Riyadh`, D22). The archive-restore dialog takes UTC days.
- On Linux hosts, `storage/` must be writable by the container user (uid 33); macOS and Windows need nothing.

