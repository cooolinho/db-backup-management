# Externe Dumps hochladen

Neben eigenen, automatisch erstellten Backups lassen sich auch extern erzeugte Dumps hochladen und
anschließend wie ein normales Backup wiederherstellen — etwa ein Export aus einer Staging-Umgebung
oder ein manuell erstelltes `mysqldump`/`pg_dump`.

## Unterstützte Formate

`.sql`, `.sql.gz`, `.zip`, `.tar.gz` — dasselbe Set wie bei eigenen Backups (siehe
[03-backups.md](03-backups.md#formate)). Das Format wird anhand des Dateinamens beim Hochladen
erkannt, nicht anhand des MIME-Typs (bei `.sql`-Dateien nicht zuverlässig).

## Anforderungen an fremde Dumps

Ein hochgeladener Dump wird vor der Freigabe automatisch geprüft (`DumpValidator`) und **abgelehnt**,
wenn er:

- ein `CREATE DATABASE`/`DROP DATABASE`/`ALTER DATABASE` bzw. bei MySQL/MariaDB zusätzlich
  `USE <db>` enthält — diese Befehle würden nicht auf die temporäre Restore-Datenbank wirken,
  sondern auf den darin genannten Namen, und sind deshalb grundsätzlich verboten.
- offensichtlich für die falsche Engine erstellt wurde (z. B. ein PostgreSQL-Dump gegen ein
  MySQL-Ziel) — dafür gibt es eine Heuristik anhand engine-typischer Inhalte, die eine
  verständliche Fehlermeldung statt eines rohen SQL-Fehlers liefert.

**Für PostgreSQL-Dumps** wird `pg_dump --no-owner` empfohlen: Owner und Berechtigungen werden nach
dem Import ohnehin automatisch auf den Owner der Live-Datenbank übertragen (siehe
[04-wiederherstellung.md](04-wiederherstellung.md#verhalten-je-datenbank-engine)), ein `--no-owner`-
Dump vermeidet dabei nur unnötige Fehlermeldungen beim Import selbst, falls der ursprüngliche
Owner auf dem Zielserver nicht existiert.

## Größenlimits

`BACKUP_UPLOAD_MAX` (Standard `2G`, siehe [02-konfiguration.md](02-konfiguration.md)) begrenzt die
Upload-Größe über die Oberfläche. Ein größerer Wert erfordert zusätzlich angepasste PHP- und
Nginx-Limits im docker/Dockerfile.

## Sehr große Dateien: `backup:import`

Für Dumps, die zu groß für einen komfortablen Browser-Upload sind, lässt sich eine bereits per
SCP/rsync auf den Server kopierte Datei direkt registrieren:

```bash
docker exec db-backup php artisan backup:import /pfad/zur/datei.sql.gz
```

Die Validierung läuft dabei synchron; der Befehl gibt sofort zurück, ob der Dump angenommen wurde.
Mit `--move` wird die Datei verschoben statt kopiert (spart Platz und Zeit bei sehr großen
Dateien, sofern Quelle und `/backups` auf demselben Dateisystem liegen).

## Ablauf in der Oberfläche

1. „Dump hochladen" in der Backup-Liste.
2. Der Eintrag erscheint sofort mit Status „Wartet" bzw. „Wird geprüft".
3. Nach abgeschlossener Prüfung wechselt der Status auf „Erfolgreich" (und die Aktion
   „Wiederherstellen" wird verfügbar) oder „Fehlgeschlagen" mit einer Fehlermeldung, die den
   Grund der Ablehnung nennt.
