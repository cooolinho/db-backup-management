# Wiederherstellung

## Ablauf

Ein Restore ersetzt niemals direkt die Live-Datenbank. Stattdessen läuft er in Schritten ab:

1. **Datei prüfen und entpacken.** Die Backup-Datei (oder ein hochgeladener Dump, siehe
   [05-upload.md](05-upload.md)) wird bei Bedarf entpackt (`zip`/`tar.gz`) und gegen die
   Ziel-Engine geprüft — ein Dump für die falsche Datenbank-Engine oder ein Dump mit einem
   eigenen `CREATE DATABASE`/`USE`-Befehl wird abgelehnt (siehe
   [05-upload.md](05-upload.md#anforderungen-an-fremde-dumps)).
2. **In eine temporäre Datenbank importieren.** Es wird eine neue, leere Datenbank
   `{db}_restore_tmp` angelegt und der Dump dort importiert — die Live-Datenbank bleibt in diesem
   Schritt komplett unberührt.
3. **Plausibilität prüfen.** Enthält die importierte temporäre Datenbank mindestens eine Tabelle?
   Schlägt der Import oder diese Prüfung fehl, wird die temporäre Datenbank wieder gelöscht, die
   Live-Datenbank bleibt unverändert, und der Restore-Eintrag zeigt den Fehler.
4. **Tauschen.** Erst wenn Schritt 2–3 erfolgreich waren, wird die Live-Datenbank durch die
   temporäre ersetzt. Ihr bisheriger Inhalt wird dabei **nicht gelöscht**, sondern als neues Archiv
   unter einem Namen wie `{db}_20260315_0312_v1` aufbewahrt.

Jeder Restore-Vorgang ist in der Oberfläche unter „Wiederherstellungen" mit Status, aktuellem
Schritt und vollständigem Protokoll nachvollziehbar.

## Verhalten je Datenbank-Engine

**MySQL/MariaDB:** Es gibt kein natives `RENAME DATABASE`. Der Tausch läuft daher über ein
einziges atomares `RENAME TABLE` aller Tabellen zwischen den drei Datenbanken (Live, temporär,
Archiv). Trigger müssen dafür kurzzeitig entfernt und danach neu angelegt werden — in diesem
Fenster von wenigen Sekunden feuern Trigger nicht. Views, gespeicherte Routinen und Events bleiben
während des gesamten Vorgangs nutzbar und werden im Anschluss in der jeweils richtigen Datenbank
neu angelegt. Die Zugriffsrechte des App-Users hängen am Datenbanknamen und bleiben automatisch
erhalten.

**PostgreSQL:** `ALTER DATABASE ... RENAME TO` ist eine transaktionale Operation. Vor dem Tausch
werden offene Verbindungen zur Live- und zur temporären Datenbank getrennt
(`pg_terminate_backend`); danach ist ein normaler Reconnect der Anwendung wieder möglich. Owner und
Berechtigungen eines importierten fremden Dumps werden automatisch auf den Owner der Live-Datenbank
übertragen, damit der App-User nach dem Tausch weiterhin lesen und schreiben kann, auch wenn der
Dump mit `--no-owner` erstellt wurde.

In beiden Fällen gilt: **Schreibvorgänge, die während oder kurz nach dem Restore an der (neuen)
Live-Datenbank ankommen, landen dort** — nicht mehr im Archiv. Das Archiv ist ein Snapshot des
Zustands unmittelbar vor dem Tausch.

## Restore auslösen

In der Backup-Liste steht bei jedem erfolgreichen, lokal vorhandenen Backup die Aktion
„Wiederherstellen" zur Verfügung. Die Bestätigung verlangt die exakte Eingabe des
Datenbanknamens, um einen versehentlichen Klick auszuschließen. Solange bereits ein Backup-,
Restore- oder Swap-Vorgang läuft, ist die Aktion gesperrt (gemeinsames Lock über alle
datenverändernden Operationen).

Für automatisierte Rollbacks, z. B. direkt aus einem Deploy-Skript:

```bash
docker exec db-backup php artisan backup:restore <backup-id> --wait --force
```

`--force` überspringt die interaktive Namensbestätigung, `--wait` lässt den Befehl bis zum Abschluss
laufen und mit einem Exit-Code ungleich null enden, falls der Restore fehlschlägt.

## Archiv-Datenbanken

Jeder Tausch erzeugt ein neues Archiv, sichtbar unter „Archiv-Datenbanken" (Name, Größe,
Zeitpunkt, Version). Zwei Aktionen stehen zur Verfügung:

- **Zurücktauschen:** Ersetzt die aktuelle Live-Datenbank durch dieses Archiv — der bisherige
  Live-Stand wird dabei wiederum als neues Archiv (`v{n+1}`) aufbewahrt. Nützlich, um einen
  Restore rückgängig zu machen.
- **Löschen:** Entfernt das Archiv endgültig. Aus Sicherheitsgründen ist das nur für Namen möglich,
  die serverseitig eindeutig als Archiv der Zieldatenbank erkannt werden — die Live-Datenbank
  selbst oder eine fremde Datenbank kann auf diesem Weg nicht gelöscht werden.

Ist die Zieldatenbank gerade nicht erreichbar, zeigt diese Seite entsprechend an, dass keine
Archive gelistet werden können, statt einen Fehler zu werfen.
