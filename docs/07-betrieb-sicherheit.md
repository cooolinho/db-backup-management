# Betrieb und Sicherheit

## Update des Images

```bash
docker compose pull   # bei einem vorgebauten Image aus einer Registry
# oder, bei lokalem Build aus dem Quellcode:
git pull
docker compose up -d --build
```

Die eigene SQLite-Datenbank (`/data`) und die Backup-Dateien (`/backups`) liegen in persistenten
Volumes bzw. Bind-Mounts und überstehen ein Update unverändert.

## Sicherung von `/data`

`/data` enthält Benutzerkonten, Backup-Metadaten, Einstellungen und das Audit-Log — nicht die
eigentlichen Backup-Dateien. Ein Verlust dieses Volumes bedeutet, alle Benutzer neu anlegen und die
Konfiguration erneut vornehmen zu müssen (die Backup-Dateien in `/backups` bleiben davon unberührt
und können nötigenfalls manuell wieder importiert werden, siehe
[05-upload.md](05-upload.md#sehr-große-dateien-backupimport)). Es empfiehlt sich, `/data` in die
eigene Server-Backup-Strategie mit aufzunehmen.

## Logs

```bash
docker compose logs -f db-backup
```

Enthält nginx-, PHP-FPM-, Scheduler- und Queue-Worker-Ausgaben aus einem Container (s6-Overlay
startet alle Dienste in einem gemeinsamen Prozessbaum). Dank `LOG_STACK=single,stderr` (Standard,
siehe `.env.example`) landen darin auch Laravel-Exceptions aus der Weboberfläche. Für das
vollständige, ungefilterte Laravel-Log direkt im Container:

```bash
docker compose exec db-backup tail -n 100 storage/logs/laravel.log
```

Die Datei liegt im beschreibbaren Layer des Containers, nicht in einem Volume, und geht bei jedem
Neu-Erzeugen des Containers verloren.

## Weitere Benutzer und Passwort-Reset

```bash
docker compose exec db-backup php artisan make:filament-user
```

Es gibt bewusst keine Benutzerverwaltung in der Oberfläche selbst — das hält die Angriffsfläche
klein. Ein Passwort-Reset läuft über denselben Befehl (E-Mail-Adresse eines bestehenden Benutzers
angeben, dann Passwort neu setzen) oder direkt per Tinker:

```bash
docker compose exec db-backup php artisan tinker
>>> \App\Models\User::where('email', 'admin@example.com')->first()->update(['password' => bcrypt('neues-passwort')]);
```

## TLS-Reverse-Proxy

Der Container selbst spricht nur HTTP. Für einen Zugriff von außerhalb des Servers sollte immer TLS
davorgeschaltet werden. Beispiel mit Traefik (Labels statt eines eigenen Ports):

```yaml
services:
  db-backup:
    build: .
    restart: unless-stopped
    env_file: [.env]
    volumes:
      - db-backup-data:/data
      - ${BACKUP_HOST_PATH:-./backups}:/backups
    networks:
      - target
      - traefik
    labels:
      traefik.enable: "true"
      traefik.http.routers.db-backup.rule: "Host(`backups.example.com`)"
      traefik.http.routers.db-backup.tls.certresolver: "letsencrypt"
      traefik.http.services.db-backup.loadbalancer.server.port: "8080"

networks:
  traefik:
    external: true
```

**Alternativ, ohne eigenen Reverse-Proxy:** Den Port nur auf `127.0.0.1` binden
(`ports: ["127.0.0.1:8090:8080"]`, bzw. `BACKUP_UI_PORT` entsprechend in Kombination mit einer
angepassten `docker-compose.yml`) und per SSH-Tunnel zugreifen:

```bash
ssh -L 8090:127.0.0.1:8090 user@server
```

Danach ist die Oberfläche lokal unter `http://localhost:8090` erreichbar, ohne dass der Port
überhaupt öffentlich exponiert wird.

## Root-Zugangsdaten

Der Backup-Manager verbindet sich für Dump, Import und den Tausch beim Restore als
Root/Superuser der Zieldatenbank — ohne diese Rechte sind weder das Anlegen temporärer
Datenbanken noch der atomare Tausch beim Restore möglich. Diese Zugangsdaten liegen ausschließlich
in der `.env` des Backup-Manager-Containers, nicht in der Oberfläche einsehbar oder änderbar.

Empfehlungen:

- Ein eigenes Root-/Superuser-Passwort für diesen Zweck verwenden, nicht das allgemeine
  Administrations-Passwort der Datenbank.
- Falls die Datenbank den Root-Zugriff standardmäßig auf `localhost` beschränkt (bei MySQL/MariaDB
  häufig der Fall), muss ein Benutzer mit Host-Wildcard angelegt werden, der Verbindungen aus dem
  Docker-Netzwerk zulässt, z. B.:

  ```sql
  CREATE USER 'root'@'%' IDENTIFIED BY '...';
  GRANT ALL PRIVILEGES ON *.* TO 'root'@'%' WITH GRANT OPTION;
  ```

  Bei PostgreSQL ist der Standard-Superuser (`postgres`) i. d. R. bereits netzwerkweit erreichbar,
  sofern `pg_hba.conf` Verbindungen aus dem Docker-Netzwerk erlaubt (bei offiziellen
  Docker-Images meist der Fall).
- `.env` entsprechend restriktiv auf dem Host sichern (Dateirechte, kein Commit ins Git).
