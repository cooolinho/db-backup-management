# Fehlerbehebung

## HTTP 500 / „Cannot modify header information — headers already sent" im Log

Diese Meldung im `docker compose logs`-Output ist nur ein Folgefehler: Laravel hat schon eine
500-Antwort verschickt, versucht danach beim Aufräumen (`terminate`) aber noch einmal, den ursprünglich
aufgetretenen Fehler zu rendern — das scheitert, weil die Antwort bereits gesendet ist, und genau diese
Kaskade landet im Log. Der eigentliche Fehler steht stattdessen nur im Laravel-Log im Container:

```bash
docker compose exec db-backup tail -n 40 storage/logs/laravel.log
```

Mit `LOG_STACK=single,stderr` (Standard seit `.env.example`, siehe
[02-konfiguration.md](02-konfiguration.md)) taucht dieser eigentliche Fehler künftig auch direkt in
`docker compose logs` auf.

Häufigste Ursache: fehlender `APP_KEY` (`MissingAppKeyException`/„No application encryption key has
been specified."). Der Key wird beim ersten Start automatisch erzeugt und nach `/data/app.key`
persistiert — tritt der Fehler trotzdem auf, prüfen:

```bash
grep '^APP_KEY' .env                       # sollte leer sein, nicht fehlen
docker compose exec db-backup cat /data/app.key
```

Ist `/data/app.key` leer oder fehlt, den Container einmal neu erzeugen (`docker compose up -d`) — das
Entrypoint-Skript generiert den Key dann neu.

## „Access denied for user 'root'@'...'"

Der konfigurierte Root-/Superuser darf nicht aus dem Docker-Netzwerk verbinden — meist, weil er bei
MySQL/MariaDB auf `'root'@'localhost'` beschränkt ist. Siehe
[07-betrieb-sicherheit.md#root-zugangsdaten](07-betrieb-sicherheit.md#root-zugangsdaten) für das
Anlegen eines netzwerkweit erreichbaren Root-Benutzers.

## „network ... not found" beim Start

`BACKUP_DOCKER_NETWORK` in der `.env` zeigt auf ein nicht existierendes Netzwerk. Verfügbare
Netzwerke prüfen:

```bash
docker network ls
```

und mit dem tatsächlichen Namen aus dem Zielprojekt abgleichen, siehe
[01-installation.md#1-netzwerkname-des-zielprojekts-ermitteln](01-installation.md#1-netzwerkname-des-zielprojekts-ermitteln).

## „Permission denied" beim Schreiben nach `/backups`

Der Container-Prozess läuft als `www-data` (UID 33). Bei einem Bind-Mount muss das Host-Verzeichnis
für diese UID beschreibbar sein:

```bash
sudo chown -R 33:33 ./backups
```

## HTTP 413 beim Hochladen eines Dumps

Die Datei überschreitet eines der konfigurierten Größenlimits. `BACKUP_UPLOAD_MAX` erhöhen und
zusätzlich die zugehörigen PHP-/Nginx-Grenzwerte (`PHP_UPLOAD_MAX_FILE_SIZE`,
`PHP_POST_MAX_SIZE`, `NGINX_CLIENT_MAX_BODY_SIZE`) im Dockerfile anpassen — siehe
[02-konfiguration.md](02-konfiguration.md#oberfläche-und-uploads).

## PostgreSQL: „database is being accessed by other users"

Tritt normalerweise nicht auf, da der Tausch offene Verbindungen zur Live- und zur temporären
Datenbank selbst trennt (`pg_terminate_backend`, siehe
[04-wiederherstellung.md](04-wiederherstellung.md#verhalten-je-datenbank-engine)). Erscheint diese
Meldung dennoch im Restore-Protokoll, deutet das auf eine Verbindung von außerhalb des
Backup-Manager-Containers hin (z. B. ein manuell geöffneter `psql`), die sich in genau diesem
Moment neu verbindet — kurz erneut versuchen.

## MySQL/MariaDB: „Lock wait timeout exceeded" beim Tausch

Eine andere, lang laufende Transaktion (z. B. ein manuell gestarteter `ALTER TABLE` oder ein
hängender App-Prozess) blockiert das für den Tausch nötige `RENAME TABLE`. Der Tausch versucht es
mit einem eigenen Timeout (`lock_wait_timeout`, serverseitig auf 30 Sekunden gesetzt) und bricht
danach kontrolliert ab — die Live-Datenbank bleibt beim vorherigen Stand, nichts geht verloren. Die
blockierende Transaktion beenden (z. B. via `SHOW PROCESSLIST` / `KILL`) und den Restore erneut
starten.

## Restore-Protokoll lesen

Jeder Restore- und Tausch-Vorgang ist unter „Wiederherstellungen" mit vollständigem, zeitgestempeltem
Protokoll einsehbar (Detailansicht → Protokoll). Bei einem Fehler nach dem eigentlichen Tausch
(selten, aber möglich bei MySQL/MariaDB beim Wiederherstellen von Views/Routinen/Events, siehe
[04-wiederherstellung.md](04-wiederherstellung.md)) enthält das Protokoll zusätzlich die
gesicherten DDL-Anweisungen, mit denen sich der vorherige Zustand nötigenfalls manuell
wiederherstellen lässt.
