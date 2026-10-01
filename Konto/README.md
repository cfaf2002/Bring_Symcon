# Bring Konto

Meldet sich am Bring!-Konto an und leitet alle Anfragen der Listen und des Konfigurators an die Bring!-Cloud weiter. Der Zugangs-Token wird automatisch erneuert; läuft er ab oder wird er ungültig, meldet sich die Instanz selbstständig neu an.

## Einstellungen

| Einstellung | Beschreibung |
| :---------- | :----------- |
| Nutzungshinweis bestätigen | Muss gesetzt sein, sonst bleibt die Instanz inaktiv |
| Instanz aktiv | Schaltet die Verbindung zur Cloud ein/aus |
| E-Mail / Passwort | Zugangsdaten des Bring!-Kontos |
| Neue Listen aus der Bring!-App | „Hinweis anzeigen“ (Standard) oder „Automatisch als Bring Liste anlegen“ |

Button **Verbindung testen** meldet sich neu an und zeigt das Ergebnis.

## Listenabgleich

Alle 15 Minuten (und beim Start) vergleicht das Konto die Listen in der Bring!-App mit den Bring Listen in Symcon:

- **Neue Liste in der App:** Meldung im Meldungsfenster, Eintrag in der Variable „Neue Listen in Bring!“ und Hinweis in der Bring Übersicht – oder, falls eingestellt, automatisches Anlegen der Instanz (neben den vorhandenen Listen).
- **Liste in der App gelöscht:** Meldung im Meldungsfenster und Hinweis in der Bring Übersicht; die Instanz bleibt bestehen und kann im Konfigurator gelöscht werden.

Jede Änderung wird nur einmal ins Meldungsfenster geschrieben.

## Status

| Code | Bedeutung |
| :--: | :-------- |
| 102 | Verbunden |
| 104 | Instanz ist deaktiviert |
| 201 | Nutzungshinweis nicht bestätigt |
| 202 | E-Mail oder Passwort fehlt |
| 203 | Anmeldung fehlgeschlagen |

## PHP-Befehle

```php
// Neu anmelden
bool EINK_Login(int $InstanzID);

// Listen sofort abgleichen: ['neu' => [...], 'geloescht' => [...]]
array EINK_CheckLists(int $InstanzID);

// Alle Listen des Kontos
array EINK_GetLists(int $InstanzID);
print_r(EINK_GetLists(12345));
```
