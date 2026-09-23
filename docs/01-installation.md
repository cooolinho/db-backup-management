# Installation

## Voraussetzungen

- Docker und Docker Compose (v2) auf dem Server, auf dem auch das zu sichernde Projekt läuft.
- Das Zielprojekt betreibt seine Datenbank (MySQL 8.x, MariaDB 10.x/11.x oder PostgreSQL 18) in
  einem eigenen Docker-Netzwerk, ohne dass der Datenbank-Port auf den Host veröffentlicht ist.
- Root- bzw. Superuser-Zugangsdaten für diese Datenbank (siehe unten, warum).

Der Backup-Manager läuft selbst als ein weiterer Container, der nachträglich in das bestehende
Docker-Netzwerk des Projekts eingehängt wird. Er braucht dafür keinen Zugriff auf den restlichen
Server, nur auf dieses eine Netzwerk.

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

```bash
cp .env.example .env
```

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

**`BACKUP_DOCKER_NETWORK`** auf den in Schritt 1 ermittelten Netzwerknamen setzen — nur relevant,
wenn `docker-compose.yml` aus diesem Projekt verwendet wird (siehe Schritt 3).

Alle weiteren `.env`-Variablen sind in [02-konfiguration.md](02-konfiguration.md) beschrieben;
die Standardwerte aus `.env.example` funktionieren für einen ersten Test.

## 3. Container starten

### Variante A: Eigenständiges Compose-File (empfohlen)

Das mitgelieferte `docker-compose.yml` hängt sich per `external: true` in das bestehende Netzwerk
des Zielprojekts ein:

```bash
docker compose up -d --build
```

### Variante B: Als Service im Compose-File des Zielprojekts

Alternativ kann der Service auch direkt in die `docker-compose.yml` des Zielprojekts eingetragen
werden — dann entfällt `BACKUP_DOCKER_NETWORK`, da das Netzwerk ohnehin geteilt wird:

```yaml
services:
  db-backup:
    build: https://github.com/<repo>.git # oder: build: ./db-backup-manager
    restart: unless-stopped
    env_file: [.env.backup]
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
  `./backups:/backups`) läuft der Container-Prozess als `www-data` mit UID 33; das Host-Verzeichnis
  muss für diese UID beschreibbar sein (`chown -R 33:33 ./backups` oder ein für alle Prozesse
  offenes Verzeichnis, z. B. `chmod 777`, je nach Sicherheitsanforderung).

## 5. Erster Login

Beim ersten Start legt `app:ensure-admin` automatisch einen Benutzer aus `BACKUP_ADMIN_NAME` /
`BACKUP_ADMIN_EMAIL` / `BACKUP_ADMIN_PASSWORD` an, sofern noch kein Benutzer existiert. Danach ist
die Oberfläche unter `http://<server>:${BACKUP_UI_PORT:-8090}` erreichbar (Login mit E-Mail und
Passwort). Es empfiehlt sich, den Port nicht öffentlich zu exponieren, sondern nur lokal zu binden
und per SSH-Tunnel oder Reverse-Proxy mit TLS zuzugreifen — siehe
[07-betrieb-sicherheit.md](07-betrieb-sicherheit.md).

Weitere Benutzer lassen sich danach über die Kommandozeile anlegen:

```bash
docker compose exec db-backup php artisan make:filament-user
```
