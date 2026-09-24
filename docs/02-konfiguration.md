# Konfiguration

Alle Variablen auf dieser Seite (außer dem letzten Abschnitt „Docker") werden in der App-`.env`
gesetzt — bei `docker run` die per `--env-file` übergebene Datei, bei `docker compose` bzw. einem
Repository-Checkout `laravel/.env` (siehe `laravel/.env.example`). Ein Teil der
Backup-Einstellungen (Zeitplan, Format, Aufbewahrung, S3) wird beim ersten Start einmalig als
Vorgabe in die Datenbank übernommen und ist danach **nur noch über die Oberfläche**
("Backup-Einstellungen") änderbar — ein späteres Ändern der `.env` hat dann keinen Effekt mehr,
außer die Einstellungen werden in der Oberfläche zurückgesetzt. Diese Variablen sind unten mit
„Vorgabewert (UI überschreibbar)" gekennzeichnet.

## Anwendung

| Variable | Standard | Wirkung |
|---|---|---|
| `APP_NAME` | `DB Backup Manager` | Name in Titel/Mails |
| `APP_ENV` | `production` | Laravel-Umgebung |
| `APP_KEY` | — | Leer lassen: wird beim ersten Start automatisch generiert und in `/data/app.key` persistiert. Ein hier gesetzter Wert überschreibt das und hat immer Vorrang |
| `LOG_STACK` | `single,stderr` | `stderr` sorgt dafür, dass Laravel-Fehler zusätzlich in `docker compose logs` erscheinen, nicht nur in `storage/logs/laravel.log` im Container |
| `APP_DEBUG` | `false` | Nie in Produktion aktivieren |
| `APP_URL` | `http://localhost:8090` | Basis-URL, u. a. für Links in Benachrichtigungen |
| `APP_LOCALE` | `de` | Oberflächensprache: `de` oder `en` |
| `APP_FALLBACK_LOCALE` | `en` | Fallback, falls ein Text in `APP_LOCALE` fehlt |

## Zieldatenbank

| Variable | Standard | Wirkung |
|---|---|---|
| `DB_CONNECTION` | `mysql` | `mysql`, `mariadb` oder `pgsql` |
| `DB_HOST` | `mysql` | Service-Name der Zieldatenbank im gemeinsamen Docker-Netzwerk |
| `DB_PORT` | `3306` | `5432` bei PostgreSQL |
| `DB_DATABASE` | `app` | Name der zu sichernden Datenbank |
| `DB_USERNAME` / `DB_PASSWORD` | `app` / `secret` | Der App-User des Zielprojekts (nur lesend/schreibend auf seiner eigenen DB nötig) |
| `DB_ROOT_USERNAME` / `DB_ROOT_PASSWORD` | `root` / — | Root/Superuser, für Dump/Import/Rename/Swap (siehe [07-betrieb-sicherheit.md](07-betrieb-sicherheit.md#root-zugangsdaten)) |
| `DB_CONNECT_TIMEOUT` | `5` | Sekunden, nach denen ein Verbindungsversuch zur Zieldatenbank aufgegeben wird (z. B. im Dashboard, wenn sie gerade nicht erreichbar ist) |

## Backup (Vorgabewert, UI überschreibbar)

| Variable | Standard | Wirkung |
|---|---|---|
| `BACKUP_PATH` | `/backups` | Ablageort im Container (auf ein Host-Volume mounten) |
| `BACKUP_SCHEDULE` | `0 3 * * *` | Cron-Ausdruck; leer = Scheduler deaktiviert |
| `BACKUP_FORMAT` | `sql.gz` | `sql`, `sql.gz`, `zip` oder `tar.gz` |
| `BACKUP_KEEP_LOCAL` | `14` | Anzahl lokal aufzubewahrender Backups; `-1` = unbegrenzt |
| `BACKUP_KEEP_S3` | `60` | Wie oben, für S3 (unabhängig von der lokalen Zahl) |
| `BACKUP_JOB_TIMEOUT` | `7200` | Sekunden, nach denen ein Backup-/Restore-Job als hängend abgebrochen wird |

Uploads externer Dumps sind von der Aufbewahrungslogik ausgenommen und werden nie automatisch
gelöscht.

## S3-kompatibler Speicher (Vorgabewert, UI überschreibbar)

| Variable | Standard | Wirkung |
|---|---|---|
| `BACKUP_S3_ENABLED` | `false` | Zusätzliche Kopie nach jedem Backup hochladen |
| `BACKUP_S3_KEY` / `BACKUP_S3_SECRET` | — | Zugangsdaten |
| `BACKUP_S3_REGION` | `eu-central-1` | AWS-Region bzw. beliebiger Wert bei S3-kompatiblen Anbietern |
| `BACKUP_S3_BUCKET` | — | Bucket-Name |
| `BACKUP_S3_ENDPOINT` | — | Leer = AWS S3; sonst z. B. MinIO- oder Hetzner-Endpoint |
| `BACKUP_S3_PATH_STYLE` | `false` | Bei MinIO meist `true` |
| `BACKUP_S3_PREFIX` | `db-backups/app` | Pfad-Präfix im Bucket |

Details und Beispiele in [03-backups.md](03-backups.md#s3-speicher).

## Oberfläche und Uploads

| Variable | Standard | Wirkung |
|---|---|---|
| `BACKUP_ADMIN_NAME` / `BACKUP_ADMIN_EMAIL` / `BACKUP_ADMIN_PASSWORD` | — | Nur beim allerersten Start wirksam (`app:ensure-admin`), solange noch kein Benutzer existiert |
| `BACKUP_UPLOAD_MAX` | `2G` | Maximale Größe für hochgeladene Dumps. Ein Wert über `2G` braucht zusätzlich angepasste `PHP_UPLOAD_MAX_FILE_SIZE`, `PHP_POST_MAX_SIZE` und `NGINX_CLIENT_MAX_BODY_SIZE` (siehe docker/Dockerfile) |

## Benachrichtigungen (nur bei Fehlern, siehe [06-benachrichtigungen-audit.md](06-benachrichtigungen-audit.md))

| Variable | Standard | Wirkung |
|---|---|---|
| `BACKUP_NOTIFY_MAIL` | — | Leer = keine Mail-Benachrichtigung |
| `BACKUP_NOTIFY_WEBHOOK_URL` | — | Leer = kein Webhook |
| `BACKUP_NOTIFY_WEBHOOK_TYPE` | `slack` | `slack`, `discord` oder `generic` — bestimmt die Nutzlast |
| `MAIL_MAILER` u. a. | `log` | Reguläre Laravel-Mail-Konfiguration |

## Docker (nur die Root-`.env`/`docker-compose.yml`, nicht von der Anwendung selbst gelesen)

Diese drei Variablen stehen in der `.env` im Repository-**Root** (nicht in `laravel/.env`) und
werden nur von `docker-compose.yml` gelesen. Bei `docker run` (siehe
[01-installation.md](01-installation.md#variante-a-docker-run-mit-dem-fertigen-image-empfohlen))
gibt es diese Variablen nicht — die gleiche Information wird direkt als Kommandozeilen-Option
übergeben.

| Variable | Standard | Wirkung | Entspricht bei `docker run` |
|---|---|---|---|
| `BACKUP_DOCKER_NETWORK` | — | Name des externen Netzwerks des Zielprojekts (siehe [01-installation.md](01-installation.md)) | `--network <name>` |
| `BACKUP_UI_PORT` | `8090` | Host-Port für die Oberfläche | `-p <port>:8080` |
| `BACKUP_HOST_PATH` | `./backups` | Host-Verzeichnis für `/backups` | `-v <pfad>:/backups` |

## `.env` mit `docker run --env-file`

`docker run --env-file .env` liest die Datei zeilenweise und wörtlich: anders als `docker compose`
werden `${VARIABLE}`-Referenzen **nicht** aufgelöst und Kommentare am Ende einer Wert-Zeile
**nicht** entfernt. Die mitgelieferte `laravel/.env.example` ist bereits entsprechend vorbereitet
(keine `${...}`-Referenzen, keine End-of-Line-Kommentare bei Werten). Wird die Datei um eigene
Zeilen ergänzt, gilt dieselbe Regel dort ebenfalls.
