# Bring für IP-Symcon

[![IP-Symcon ab 8.1](https://img.shields.io/badge/IP--Symcon-ab_8.1-0b6fb3.svg)](https://www.symcon.de)
[![Optimiert für Symcon 9.0](https://img.shields.io/badge/optimiert_f%C3%BCr-Symcon_9.0-0b6fb3.svg)](https://www.symcon.de/de/service/dokumentation/installation/migrationen/v81-v90-q1-2026/)
[![Modul-Version 1.2 (Build 15)](https://img.shields.io/badge/Modul--Version-1.2_(Build_15)-informational.svg)](library.json)
[![Tests](https://github.com/cfaf2002/Bring_Symcon/actions/workflows/tests.yml/badge.svg)](https://github.com/cfaf2002/Bring_Symcon/actions/workflows/tests.yml)
[![PHP 8.3 und 8.5](https://img.shields.io/badge/PHP-8.3_%7C_8.5-777bb4.svg?logo=php&logoColor=white)](https://www.php.net)
[![SDK: IPSModuleStrict](https://img.shields.io/badge/SDK-IPSModuleStrict-success.svg)](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/module/)
[![Variablen: Darstellungen](https://img.shields.io/badge/Variablen-Darstellungen-success.svg)](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/darstellungen/)
[![Kachel-Visualisierung: HTML-SDK](https://img.shields.io/badge/Kachel--Visualisierung-HTML--SDK-orange.svg)](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/html-sdk/)
[![Farbschema: Symcon-Design, Dunkel, Hell](https://img.shields.io/badge/Farbschema-Symcon--Design_%7C_Dunkel_%7C_Hell-blueviolet.svg)](STYLEGUIDE.md)
![Sprache: Deutsch](https://img.shields.io/badge/Sprache-Deutsch-blueviolet.svg)
[![Lizenz: MIT](https://img.shields.io/badge/Lizenz-MIT-green.svg)](LICENSE)
[![Cloud: Bring! Web-App (inoffiziell)](https://img.shields.io/badge/Cloud-Bring%21_Web--App_(inoffiziell)-lightgrey.svg)](https://www.getbring.com)

Bindet Bring!-Einkaufslisten in IP-Symcon ein: Liste anzeigen, Artikel hinzufügen, abhaken und entfernen, Benachrichtigungen an alle Mitglieder senden – mit eigener Kachel für die Visualisierung.

> **Hinweis:** Bring! bietet keine offizielle Schnittstelle. Das Modul nutzt die Schnittstelle der Bring!-Web-App, steht in keiner Verbindung zur Bring! Labs AG und ist nur für die private Nutzung gedacht. Ändert Bring! die Schnittstelle, kann das Modul ohne Vorwarnung aufhören zu funktionieren.

## Inhalt

| Modul | Typ | Aufgabe |
| :---- | :-- | :------ |
| [Bring Konto](Konto/README.md) | I/O | Anmeldung am Bring!-Konto, Token-Verwaltung |
| [Bring Konfigurator](Konfigurator/README.md) | Konfigurator | Legt die Listen des Kontos als Instanzen an |
| [Bring Liste](Liste/README.md) | Gerät | Eine Liste mit Variablen, Kachel und Benachrichtigungen |
| [Bring Übersicht](Uebersicht/README.md) | Gerät | Alle Listen in einer Kachel, Antippen öffnet die Liste, mit Zurück-Knopf |

## Voraussetzungen

- IP-Symcon ab Version 8.1
- Bring!-Konto mit E-Mail und Passwort (bei Anmeldung über Google/Apple zuerst in der App ein Passwort setzen)

## Installation

1. Im Objektbaum unter *Kern Instanzen → Modules* die URL `https://github.com/cfaf2002/Bring_Symcon` hinzufügen.
2. Instanz **Bring Konfigurator** anlegen – die Konto-Instanz wird automatisch mit erstellt.
3. In der Konto-Instanz den Nutzungshinweis bestätigen, E-Mail und Passwort eintragen und übernehmen.
4. Im Konfigurator die gewünschten Listen erstellen – und bei Bedarf die „Übersicht“, die alle Listen in einer Kachel zusammenfasst.

## Datenfluss

```
Bring Liste         ─┐
Bring Liste         ─┼──►  Bring Konto  ──►  Bring!-Cloud
Bring Konfigurator  ─┘
```

## GUIDs

| Modul | Präfix | GUID |
| :---- | :----: | :--: |
| Bring Konto | EINK | {97F6071D-028A-4BFF-B873-1DC1C67F66E4} |
| Bring Konfigurator | EINK | {ECCAFA95-80A1-44A5-900E-57C483BF0DFF} |
| Bring Liste | EINK | {7EDEB801-A287-42F4-B60E-88DEC71E6305} |
| Bring Übersicht | EINK | {354B6E1E-7D2A-476B-AEFC-1435851C1895} |

## Changelog

**Version 1.2 (Build 15)**
- Anmeldung: Nach abgelehntem Passwort wird mit wachsender Wartezeit (15 Minuten bis 6 Stunden) erneut versucht statt alle 15 Minuten – schützt vor einer Kontosperre; „Übernehmen“ und „Verbindung testen“ versuchen es sofort
- Netz- und Serverfehler (keine Verbindung, HTTP 5xx/429) löschen die Tokens nicht mehr und setzen das Konto nicht mehr auf „Anmeldung fehlgeschlagen“
- Token-Erneuerung ist gegen gleichzeitige Abläufe gesperrt, ein frisch erneuerter Token wird nicht mehr verworfen
- Kachel: Schlägt eine Änderung fehl (z. B. Abhaken ohne Internet), zeigt die Kachel wieder den echten Stand und eine Meldung; der `⋮`-Knopf hat eine Klickfläche von 36 px
- „Letzte Aktualisierung“ ändert sich nur noch bei einem echten Abruf der Liste, nicht beim stündlichen Katalog-Abgleich
- E-Mail-Adresse nicht mehr im Debug; Listen ohne Namen im Formular abgesichert

**Version 1.1 (Build 14)**
- Hausstil: Regel für die Modulliste (`vendor` gesetzt, höchstens ein Alias) in `STYLEGUIDE.md` und Strukturprüfung ergänzt

**Version 1.1 (Build 13)**
- Einheitliches Design nach `STYLEGUIDE.md`: Kachel-Grundlage (Farben, Schrift, Radien, Zustandsfarben) und Einstellung „Farbschema der Kachel“ (Symcon-Design, Dunkel, Hell); Kachel-Datei heißt `tile.html`; einheitliche Badges; gemeinsamer Test-Workflow mit Struktur- und Ladetest
- LICENSE-Datei ergänzt; im Symcon-Design Akzentfarbe der Visualisierung, Bring-Rot bei „Dunkel“ und „Hell“; Artikel-Dialog folgt dem Farbschema

**Version 1.0 (Build 12)**
- Bring Konto gleicht alle 15 Minuten die Listen der Bring!-App mit Symcon ab: neue und in der App gelöschte Listen erscheinen im Meldungsfenster, in der Variable „Neue Listen in Bring!“ und als Hinweis in der Bring Übersicht
- Optional: neue Listen automatisch als Bring Liste anlegen
- Button „Listen jetzt abgleichen“ und Befehl EINK_CheckLists

**Version 1.0 (Build 11)**
- Neues Modul „Bring Übersicht“: alle Listen in einer Kachel, Antippen öffnet die Liste, Zurück-Knopf zur Übersicht
- Konfigurator bietet die Übersicht zum Anlegen an

**Version 1.0 (Build 10)**
- Bring!-Artikelkatalog wird automatisch einmal täglich abgeglichen (unabhängig vom Aktualisierungsintervall); fehlt er, alle 15 Minuten neuer Versuch, bei Ausfall bleibt der bisherige erhalten
- Button „Artikelkatalog neu laden“ und Befehl EINK_ReloadCatalog

**Version 1.0 (Build 9)**
- Kachel: Vorschläge mit Symbol beim Tippen (Bring!-Katalog, zuletzt verwendete und eigene Artikel), Auswahl per Maus oder Pfeiltasten/Enter
- Benachrichtigungs-Knöpfe melden jetzt, ob das Senden geklappt hat (bei Fehlern mit Grund, zusätzlich im Meldungsfenster)
- Artikelnamen werden immer über den Bring!-Katalog angezeigt (z. B. „Müsli“ statt „Müesli“)

**Version 1.0 (Build 8)**
- Kachel: Texte, Eingabefeld und Knöpfe wieder gut lesbar (feste Farben für dunkles/helles Design), größere Schrift

**Version 1.0 (Build 7)**
- Kachel überarbeitet: kein doppelter Titel mehr, einheitliche Schrift, ruhigere Artikel-Kacheln, passt sich hellem und dunklem Design an
- Artikel bearbeiten auch per Rechtsklick

**Version 1.0 (Build 6)**
- Hersteller in der Instanzliste: Bring! Labs AG

**Version 1.0 (Build 5)**
- Umbenannt: Bring Konto, Bring Konfigurator, Bring Liste (Präfix EINK und GUIDs unverändert)

**Version 1.0 (Build 4)**
- Doppelte Einträge beim Hinzufügen einer Instanz entfernt (Aliase)

**Version 1.0 (Build 3)**
- Korrektur: Instanzen ließen sich nicht anlegen (GetCompatibleParents statt ConnectParent)

**Version 1.0 (Build 2)**
- Korrektur: Module wurden von Symcon nicht geladen (Basisklasse IPSModuleStrict)

**Version 1.0 (Build 1)**
- Erste Version: Konto, Konfigurator, Liste mit Variablen, eigener Kachel, Benachrichtigungen und automatischer Aktualisierung

## Autor

Armin Frohwerk
