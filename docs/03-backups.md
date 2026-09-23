# Backups

## Zeitplan

Unter „Backup-Einstellungen" steht eine Auswahl an Presets zur Verfügung (stündlich, alle 6
Stunden, täglich, wöchentlich) sowie ein freier Cron-Ausdruck für alle anderen Fälle. Ein leerer
Ausdruck deaktiviert den Scheduler vollständig — es lassen sich dann nur noch manuelle Backups
auslösen.

Ein interner Tick (`backup:tick`, per Laravel-Scheduler minütlich ausgeführt, siehe
[08-entwicklung.md](08-entwicklung.md#architektur)) prüft den Cron-Ausdruck und stößt bei Fälligkeit
einen Hintergrund-Job an. Derselbe Tick prüft auch, ob seit mehr als der doppelten erwarteten
Intervalldauer kein erfolgreiches Backup mehr gelaufen ist, und warnt in diesem Fall einmalig (siehe
[06-benachrichtigungen-audit.md](06-benachrichtigungen-audit.md#watchdog)).

## Formate

| Format | Inhalt |
|---|---|
| `sql` | Reiner unkomprimierter SQL-Dump |
| `sql.gz` | SQL-Dump, gzip-komprimiert (Standard) |
| `zip` | SQL-Dump in einer ZIP-Datei |
| `tar.gz` | SQL-Dump in einem gzip-komprimierten Tarball |

Jede Backup-Datei bekommt einen sprechenden Dateinamen mit Zeitstempel sowie eine SHA-256-Prüfsumme,
die in der Oberfläche einsehbar ist.

## Aufbewahrung

`BACKUP_KEEP_LOCAL` und `BACKUP_KEEP_S3` (siehe [02-konfiguration.md](02-konfiguration.md))
bestimmen unabhängig voneinander, wie viele der neuesten Backups jeweils behalten werden — ältere
werden nach jedem erfolgreichen Lauf automatisch gelöscht. `-1` bedeutet unbegrenzt. Manuell
hochgeladene Dumps (siehe [05-upload.md](05-upload.md)) zählen nicht mit und werden nie automatisch
entfernt.

## S3-Speicher

Nach Aktivierung (`BACKUP_S3_ENABLED=true` oder in der Oberfläche) wird jedes neue Backup
zusätzlich zur lokalen Kopie in den konfigurierten Bucket hochgeladen. Zugangsdaten kommen
ausschließlich aus der `.env` (`BACKUP_S3_*`), nicht aus der Oberfläche.

**MinIO** (lokal, S3-kompatibel):

```env
BACKUP_S3_ENABLED=true
BACKUP_S3_ENDPOINT=http://minio:9000
BACKUP_S3_PATH_STYLE=true
BACKUP_S3_KEY=minioadmin
BACKUP_S3_SECRET=minioadmin
BACKUP_S3_BUCKET=db-backups
```

**Hetzner Object Storage:**

```env
BACKUP_S3_ENABLED=true
BACKUP_S3_ENDPOINT=https://fsn1.your-objectstorage.com
BACKUP_S3_PATH_STYLE=false
BACKUP_S3_REGION=fsn1
BACKUP_S3_KEY=...
BACKUP_S3_SECRET=...
BACKUP_S3_BUCKET=db-backups
```

Ein Restore ist in dieser Version ausschließlich aus einer lokal vorhandenen Datei möglich —
ein Restore direkt aus S3 ist bewusst nicht vorgesehen (siehe
[04-wiederherstellung.md](04-wiederherstellung.md)); eine auf S3 liegende Datei muss also zunächst
wieder lokal verfügbar sein (z. B. durch Herunterladen und erneutes Hochladen, siehe
[05-upload.md](05-upload.md)).

## Manuell auslösen

**Über die Oberfläche:** Schaltfläche „Backup jetzt erstellen" in der Backup-Liste.

**Über die Kommandozeile**, z. B. aus einem Deploy-Skript, das vor einem riskanten Deployment noch
einen aktuellen Stand sichern soll:

```bash
docker compose exec db-backup php artisan backup:run --wait
```

`--wait` lässt den Befehl synchron laufen und mit einem Exit-Code ungleich null enden, falls das
Backup fehlschlägt — geeignet, um eine CI/CD-Pipeline daran zu koppeln:

```bash
#!/bin/sh
set -e
docker compose exec -T db-backup php artisan backup:run --wait
docker compose pull
docker compose up -d
```

Ohne `--wait` wird der Job nur eingereiht und der Befehl kehrt sofort zurück; der Fortschritt ist
dann in der Oberfläche zu sehen.
