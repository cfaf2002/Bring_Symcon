# Einkaufsliste (Bring!) für IP-Symcon

Bindet Bring!-Einkaufslisten in IP-Symcon ein: Liste anzeigen, Artikel hinzufügen, abhaken und entfernen, Benachrichtigungen an alle Mitglieder senden – mit eigener Kachel für die Visualisierung.

> **Hinweis:** Bring! bietet keine offizielle Schnittstelle. Das Modul nutzt die Schnittstelle der Bring!-Web-App, steht in keiner Verbindung zur Bring! Labs AG und ist nur für die private Nutzung gedacht. Ändert Bring! die Schnittstelle, kann das Modul ohne Vorwarnung aufhören zu funktionieren.

## Inhalt

| Modul | Typ | Aufgabe |
| :---- | :-- | :------ |
| [Einkaufsliste Konto](Konto/README.md) | I/O | Anmeldung am Bring!-Konto, Token-Verwaltung |
| [Einkaufsliste Konfigurator](Konfigurator/README.md) | Konfigurator | Legt die Listen des Kontos als Instanzen an |
| [Einkaufsliste](Liste/README.md) | Gerät | Eine Liste mit Variablen, Kachel und Benachrichtigungen |

## Voraussetzungen

- IP-Symcon ab Version 8.1
- Bring!-Konto mit E-Mail und Passwort (bei Anmeldung über Google/Apple zuerst in der App ein Passwort setzen)

## Installation

1. Im Objektbaum unter *Kern Instanzen → Modules* die URL `https://github.com/cfaf2002/Bring_Symcon` hinzufügen.
2. Instanz **Einkaufsliste Konfigurator** anlegen – die Konto-Instanz wird automatisch mit erstellt.
3. In der Konto-Instanz den Nutzungshinweis bestätigen, E-Mail und Passwort eintragen und übernehmen.
4. Im Konfigurator die gewünschten Listen erstellen.

## Datenfluss

```
Einkaufsliste (Liste)  ─┐
Einkaufsliste (Liste)  ─┼──►  Einkaufsliste Konto  ──►  Bring!-Cloud
Konfigurator           ─┘
```

## GUIDs

| Modul | Präfix | GUID |
| :---- | :----: | :--: |
| Einkaufsliste Konto | EINK | {97F6071D-028A-4BFF-B873-1DC1C67F66E4} |
| Einkaufsliste Konfigurator | EINK | {ECCAFA95-80A1-44A5-900E-57C483BF0DFF} |
| Einkaufsliste | EINK | {7EDEB801-A287-42F4-B60E-88DEC71E6305} |

## Changelog

**Version 1.0 (Build 2)**
- Korrektur: Module wurden von Symcon nicht geladen (Basisklasse IPSModuleStrict)

**Version 1.0 (Build 1)**
- Erste Version: Konto, Konfigurator, Liste mit Variablen, eigener Kachel, Benachrichtigungen und automatischer Aktualisierung

## Autor

Armin Frohwerk
