# Installation

## Voraussetzungen

- Docker auf dem Server, auf dem auch das zu sichernde Projekt läuft. Docker Compose (v2) wird nur
  für die Varianten B und C unten gebraucht, nicht für den direkten `docker run`-Betrieb (Variante A).
- Das Zielprojekt betreibt seine Datenbank (MySQL 8.x, MariaDB 10.x/11.x oder PostgreSQL 18) in
  einem eigenen Docker-Netzwerk, ohne dass der Datenbank-Port auf den Host veröffentlicht ist.
- Root- bzw. Superuser-Zugangsdaten für diese Datenbank (siehe unten, warum).

Der Backup-Manager läuft selbst als ein weiterer Container, der nachträglich in das bestehende
Docker-Netzwerk des Projekts eingehängt wird. Er braucht dafür keinen Zugriff auf den restlichen
Server, nur auf dieses eine Netzwerk. Das fertige Image liegt auf der GitHub Container Registry
unter `ghcr.io/cooolinho/db-backup-management` — ein lokaler Build aus dem Quellcode ist nicht
nötig.

## 1. Netzwerkname des Zielprojekts ermitteln

```bash
docker network ls
```

Der Name folgt meist dem Muster `<projektordner>_default` oder ist explizit in der
`docker-compose.yml` des Zielprojekts unter `networks:` benannt. Im Zweifel:

```bash
docker inspect <name-des-db-containers> --format '{{json .NetworkSettings.Networks}}'
```

zeigt, in welchem Netzwerk der Datenbank-Container tatsächlich hängt.

## 2. `.env` anlegen

Auf dem Zielserver reicht ein leeres Verzeichnis, ohne Repository-Checkout. Für `docker run`
(Variante A) wird nur die App-Konfiguration gebraucht — die Variablen aus `laravel/.env.example`
im Repository:

```bash
mkdir -p /opt/db-backup/backups && cd /opt/db-backup
curl -fsSL https://raw.githubusercontent.com/cooolinho/db-backup-management/main/laravel/.env.example -o .env
chmod 600 .env
```

Für eine konkrete, gepinnte Version statt der jeweils aktuellen `main`-Vorlage `main` im Pfad durch
den entsprechenden Git-Tag ersetzen, z. B. `.../v1.2.3/laravel/.env.example`.

**Wichtig beim Bearbeiten der `.env`:** Wird der Container später per `docker run --env-file`
gestartet (Variante A unten), liest Docker die Datei zeilenweise und wörtlich — anders als
`docker compose` werden `${VARIABLE}`-Referenzen **nicht** aufgelöst und Kommentare am Ende einer
Zeile mit Wert **nicht** entfernt. Die mitgelieferte `laravel/.env.example` ist bereits
entsprechend vorbereitet; das gilt nur, falls eigene Zeilen ergänzt werden.

**Datenbank-Block 1:1 aus dem Zielprojekt übernehmen:** `DB_CONNECTION`, `DB_HOST` (der
Service-Name der Datenbank im Compose-File des Zielprojekts, z. B. `mysql` oder `db`), `DB_PORT`,
`DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` müssen exakt so gesetzt werden, wie sie auch das
Zielprojekt selbst verwendet — der Backup-Manager verbindet sich als derselbe App-Client.

**Zusätzlich: Root-/Superuser-Zugangsdaten** (`DB_ROOT_USERNAME`, `DB_ROOT_PASSWORD`). Diese
werden für Operationen gebraucht, die der normale App-User nicht darf: Datenbanken anlegen und
löschen sowie die Umbenennung/den Tausch beim Restore. Standard-Benutzername ist `root`
(MySQL/MariaDB) bzw. `postgres` (PostgreSQL). Falls dieser Benutzer noch nicht von außerhalb des
Containers verbunden werden darf, siehe die Hinweise in
[07-betrieb-sicherheit.md](07-betrieb-sicherheit.md#root-zugangsdaten).

Alle weiteren `.env`-Variablen sind in [02-konfiguration.md](02-konfiguration.md) beschrieben;
die Standardwerte aus `laravel/.env.example` funktionieren für einen ersten Test.

## 3. Container starten

### Variante A: `docker run` mit dem fertigen Image (empfohlen)

Kein Checkout des Repositories nötig — nur die `.env` aus Schritt 2:

```bash
docker run -d \
  --name db-backup \
  --restart unless-stopped \
  --env-file .env \
  --network <netzwerk-des-zielprojekts> \
  -p 8090:8080 \
  -v db-backup-data:/data \
  -v /opt/db-backup/backups:/backups \
  ghcr.io/cooolinho/db-backup-management:latest
```

- `--env-file .env` — die in Schritt 2 angelegte Datei.
- `--network <netzwerk-des-zielprojekts>` — der in Schritt 1 ermittelte Netzwerkname (entspricht
  `BACKUP_DOCKER_NETWORK` bei der Compose-Variante).
- `-p 8090:8080` — Host-Port für die Oberfläche links vom Doppelpunkt anpassen (entspricht
  `BACKUP_UI_PORT`).
- `-v db-backup-data:/data` — persistentes Volume für die eigene SQLite-Datenbank (Benutzer,
  Backup-Metadaten, Einstellungen, Audit-Log, `APP_KEY`). **Muss** persistent bleiben.
- `-v /opt/db-backup/backups:/backups` — Host-Verzeichnis für die Backup-Dateien selbst
  (entspricht `BACKUP_HOST_PATH`); Pfad nach Bedarf anpassen. Siehe Abschnitt 4 zu den nötigen
  Rechten.
- Am Ende statt `:latest` einen konkreten Git-Tag wie `:1.2.3` verwenden, um eine bestimmte Version
  zu fixieren.

Alle Befehle im weiteren Handbuch gehen von diesem Containernamen aus, z. B.
`docker exec db-backup php artisan ...` oder `docker logs -f db-backup`.

### Variante B: Eigenständiges Compose-File aus dem Repository

Für ein deklaratives Setup mit `docker compose` statt eines einzelnen `docker run`-Aufrufs, per
Checkout des Repositories. Das mitgelieferte `docker-compose.yml` zieht standardmäßig dasselbe
`ghcr.io`-Image (ein lokaler Build ist mit `--build` weiterhin möglich) und hängt sich per
`external: true` in das bestehende Netzwerk des Zielprojekts ein:

```bash
git clone https://github.com/cooolinho/db-backup-management.git
cd db-backup-management
cp .env.example .env                    # BACKUP_DOCKER_NETWORK / _UI_PORT / _HOST_PATH
cp laravel/.env.example laravel/.env    # App-Konfiguration, wie in Schritt 2 ausfüllen
docker compose up -d
```

### Variante C: Als Service im Compose-File des Zielprojekts

Alternativ kann der Service auch direkt in die `docker-compose.yml` des Zielprojekts eingetragen
werden — dann entfällt `BACKUP_DOCKER_NETWORK`, da das Netzwerk ohnehin geteilt wird:

```yaml
services:
  db-backup:
    image: ghcr.io/cooolinho/db-backup-management:latest
    container_name: db-backup
    restart: unless-stopped
    env_file: [.env.backup] # Inhalt wie laravel/.env.example im Repository
    ports:
      - "8090:8080"
    volumes:
      - db-backup-data:/data
      - ./backups:/backups
    networks:
      - default # dasselbe Netzwerk wie die Datenbank
volumes:
  db-backup-data:
```

## 4. Volumes und Rechte

- `/data` — die eigene SQLite-Datenbank des Tools (Benutzer, Backup-Metadaten, Einstellungen,
  Audit-Log, `APP_KEY`). Muss ein persistentes Volume sein, sonst gehen bei jedem Neustart alle
  Benutzer und die Backup-Historie verloren.
- `/backups` — die eigentlichen Backup-Dateien. Bei einem Bind-Mount (`BACKUP_HOST_PATH` bzw.
  `./backups:/backups` bzw. das Verzeichnis aus Variante A) läuft der Container-Prozess als
  `www-data` mit UID 33; das Host-Verzeichnis muss für diese UID beschreibbar sein
  (`chown -R 33:33 /opt/db-backup/backups` oder ein für alle Prozesse offenes Verzeichnis, z. B.
  `chmod 777`, je nach Sicherheitsanforderung).

## 5. Erster Login

Beim ersten Start legt `app:ensure-admin` automatisch einen Benutzer aus `BACKUP_ADMIN_NAME` /
`BACKUP_ADMIN_EMAIL` / `BACKUP_ADMIN_PASSWORD` an, sofern noch kein Benutzer existiert. Danach ist
die Oberfläche unter `http://<server>:8090` (bzw. dem gewählten Port) erreichbar (Login mit E-Mail
und Passwort). Es empfiehlt sich, den Port nicht öffentlich zu exponieren, sondern nur lokal zu
binden und per SSH-Tunnel oder Reverse-Proxy mit TLS zuzugreifen — siehe
[07-betrieb-sicherheit.md](07-betrieb-sicherheit.md).

Weitere Benutzer lassen sich danach über die Kommandozeile anlegen:

```bash
docker exec db-backup php artisan make:filament-user
```
