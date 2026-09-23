# DB Backup Manager

A self-contained Docker container that drops into an existing project's Docker network and gives
it scheduled, restorable database backups through a small password-protected web UI — no exposed
database port required.

## 📖 About

Most projects that run their database only inside a private Docker network have no easy way to
back it up, download a copy, or roll back a bad deployment without shelling into the container.
This tool adds exactly that: it joins the project's existing Docker network as a second container,
connects to the database as an application user for read-only work and as root/superuser only for
the operations that actually need it (dump, import, rename/swap), and exposes a Laravel/Filament UI
on its own port.

A restore never overwrites data in place. It imports the dump into a temporary database first, and
only swaps it in once that import succeeded — the database's previous content is kept as a
timestamped archive, never deleted outright. MySQL, MariaDB and PostgreSQL are all supported.

## ✨ Features

- Scheduled backups (cron expression or presets), manual backups, and external dump uploads, all
  in `.sql`, `.sql.gz`, `.zip` or `.tar.gz`
- Safe restore: import into a temporary database, verify, then atomically swap — the previous
  database state is preserved as a versioned archive (`{db}_{date}_{time}_v{n}`), never lost
- Archive management: list, swap back in, or permanently delete old archived databases
- Optional second copy on any S3-compatible storage (AWS S3, MinIO, Hetzner, …), with independent
  local/remote retention
- Failure notifications (database, mail, Slack/Discord/generic webhook) and a stale-backup watchdog
- Full audit log of every user and system action
- Dashboard with target database reachability, last backup, next run, storage usage and archive
  count
- German and English UI (`APP_LOCALE`)
- CLI commands (`backup:run --wait`, `backup:restore --wait`) for scripting into deploy pipelines

## 🚀 Getting Started

```bash
cp .env.example .env
# Fill in DB_* (copied from the target project) and BACKUP_DOCKER_NETWORK (see docs)
docker compose up -d --build
```

The UI is then reachable at `http://localhost:${BACKUP_UI_PORT:-8090}`. A first admin user is
created automatically from `BACKUP_ADMIN_*` in `.env`.

Full walkthrough, including how to find the target project's Docker network and how to hand over
root database credentials: [docs/01-installation.md](docs/01-installation.md).

## 📋 Usage

Trigger a backup and gate a deployment on it succeeding:

```bash
docker compose exec db-backup php artisan backup:run --wait
```

Restore a backup non-interactively (e.g. an automated rollback):

```bash
docker compose exec db-backup php artisan backup:restore <backup-id> --wait --force
```

Import a large dump already copied onto the server:

```bash
docker compose exec db-backup php artisan backup:import /path/to/dump.sql.gz
```

Everything else — scheduling, retention, S3, restore behavior per engine, notifications, the audit
log — is covered in [📚 Documentation](#-documentation).

## 📁 Project Structure

```
app/
  Backup/          Drivers (MySQL/MariaDB/PostgreSQL), BackupService, RestoreService,
                    RetentionService, ArchiveNamer, DumpValidator, OperationLock
  Console/Commands/ backup:run, backup:restore, backup:import, backup:tick, app:ensure-admin
  Filament/         Resources, Pages, Actions and Widgets for the web UI
  Jobs/             Queue jobs for backup, restore, swap and archive deletion
  Support/          Audit log writer, FailureNotifier
docker/
  dev/              PHP CLI + database clients image for running the test suite
docs/               German-language operations handbook (see below)
docker-compose.yml      Standalone deployment (joins an external Docker network)
docker-compose.dev.yml  Local database fixtures + dev/test tooling
```

## 📚 Documentation

An in-depth German-language handbook lives under [`docs/`](docs/README.md):

1. [Installation](docs/01-installation.md)
2. [Configuration](docs/02-konfiguration.md)
3. [Backups](docs/03-backups.md)
4. [Restore](docs/04-wiederherstellung.md)
5. [Uploading external dumps](docs/05-upload.md)
6. [Notifications and audit log](docs/06-benachrichtigungen-audit.md)
7. [Operations and security](docs/07-betrieb-sicherheit.md)
8. [Development](docs/08-entwicklung.md)
9. [Troubleshooting](docs/09-fehlerbehebung.md)

## 🔗 References

- [Laravel](https://laravel.com/docs)
- [Filament](https://filamentphp.com/docs)
- [Spatie DB Dumper](https://github.com/spatie/db-dumper)

## 📄 License

This project is open-sourced software licensed under the [MIT license](LICENSE).
