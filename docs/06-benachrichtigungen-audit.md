# Benachrichtigungen und Audit-Log

## Benachrichtigungen bei Fehlern

Es wird bewusst **nur bei Fehlern** benachrichtigt, nicht bei jedem erfolgreichen Backup — das
hielte die Zahl der Nachrichten im Normalbetrieb gering. Ein fehlgeschlagenes Backup, ein
fehlgeschlagener Restore, ein fehlgeschlagener Tausch oder ein fehlgeschlagenes Löschen eines
Archivs löst automatisch bis zu drei Kanäle aus (jeder unabhängig, ein Fehler in einem Kanal
verhindert nicht die anderen):

- **Datenbank-Benachrichtigung** an alle Benutzer, sichtbar im Glockensymbol der Oberfläche.
- **E-Mail** an `BACKUP_NOTIFY_MAIL`, sofern gesetzt. Nutzt die reguläre Laravel-`MAIL_*`-
  Konfiguration.
- **Webhook** an `BACKUP_NOTIFY_WEBHOOK_URL`, sofern gesetzt. `BACKUP_NOTIFY_WEBHOOK_TYPE` bestimmt
  das Format der Nutzlast:

  | Typ | Nutzlast |
  |---|---|
  | `slack` | `{"text": "..."}` |
  | `discord` | `{"content": "..."}` |
  | `generic` | `{"event": "...", "database": "...", "error": "...", "occurred_at": "...", "url": "..."}` |

Eine fehlgeschlagene Validierung eines hochgeladenen Dumps (siehe [05-upload.md](05-upload.md))
erzeugt dagegen nur eine Benachrichtigung an den hochladenden Benutzer, keine Mail/Webhook — sie
betrifft ja keinen produktiven Datenbestand.

## Watchdog

Zusätzlich zu echten Fehlern wird gewarnt, wenn **seit mehr als der doppelten erwarteten
Intervalldauer kein erfolgreiches Backup mehr gelaufen ist** — ein Hinweis auf einen hängenden
Queue-Worker oder einen anderen stillen Ausfall, bei dem gar kein Job mehr fehlschlägt, weil gar
keiner mehr läuft. Die Prüfung läuft bei jedem Scheduler-Tick; pro „Stillstand-Episode" wird nur
einmal gewarnt, nicht bei jedem Tick erneut. Ein ausgefallener Scheduler selbst wird von s6
(dem Prozess-Supervisor im Container) automatisch neu gestartet und braucht daher keine eigene
Überwachung.

## Audit-Log

Alle sicherheits- oder datenrelevanten Aktionen werden protokolliert und sind unter „Audit-Log"
(nur lesend, mit Filtern nach Aktion, Benutzer und Zeitraum) einsehbar:

| Aktion | Auslöser |
|---|---|
| `auth.login` / `auth.login_failed` / `auth.logout` | Anmeldung/Abmeldung |
| `backup.requested` / `backup.created` / `backup.failed` | Backup angestoßen, fertig, fehlgeschlagen |
| `backup.downloaded` / `backup.deleted` / `backup.uploaded` | Aktionen in der Backup-Liste |
| `restore.requested` / `restore.succeeded` / `restore.failed` | Wiederherstellung angestoßen, fertig, fehlgeschlagen |
| `archive.swapped` / `archive.dropped` | Archiv zurückgetauscht bzw. gelöscht |
| `settings.updated` | Änderung der Backup-Einstellungen, mit Diff der geänderten Felder |

Jeder Eintrag speichert Benutzer, IP-Adresse, Zeitpunkt sowie — je nach Aktion — zusätzliche
Details (z. B. den genauen Diff bei `settings.updated`), einsehbar über die Detailansicht.
