# Handbuch

Dieses Handbuch beschreibt den Betrieb des DB-Backup-Managers im Detail. Für einen schnellen
Überblick siehe die [README](../README.md) im Projekt-Wurzelverzeichnis.

1. [Installation](01-installation.md) — Voraussetzungen, `.env`, Compose-Varianten, erster Login
2. [Konfiguration](02-konfiguration.md) — Alle Umgebungsvariablen im Detail
3. [Backups](03-backups.md) — Zeitplan, Formate, Aufbewahrung, S3, manuelle Läufe
4. [Wiederherstellung](04-wiederherstellung.md) — Restore-Ablauf, Swap-Verhalten je Engine, Archive
5. [Externe Dumps hochladen](05-upload.md) — Formate, Anforderungen, Größenlimits, `backup:import`
6. [Benachrichtigungen und Audit-Log](06-benachrichtigungen-audit.md) — Mail, Webhooks, Watchdog, Audit-Aktionen
7. [Betrieb und Sicherheit](07-betrieb-sicherheit.md) — Updates, Benutzerverwaltung, TLS, Root-Zugangsdaten
8. [Entwicklung](08-entwicklung.md) — Dev-Setup, Tests, Architektur
9. [Fehlerbehebung](09-fehlerbehebung.md) — Bekannte Probleme und ihre Lösung
