# Bring Konto

Meldet sich am Bring!-Konto an und leitet alle Anfragen der Listen und des Konfigurators an die Bring!-Cloud weiter. Der Zugangs-Token wird automatisch erneuert; läuft er ab oder wird er ungültig, meldet sich die Instanz selbstständig neu an.

## Einstellungen

| Einstellung | Beschreibung |
| :---------- | :----------- |
| Nutzungshinweis bestätigen | Muss gesetzt sein, sonst bleibt die Instanz inaktiv |
| Instanz aktiv | Schaltet die Verbindung zur Cloud ein/aus |
| E-Mail / Passwort | Zugangsdaten des Bring!-Kontos |

Button **Verbindung testen** meldet sich neu an und zeigt das Ergebnis.

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

// Alle Listen des Kontos
array EINK_GetLists(int $InstanzID);
print_r(EINK_GetLists(12345));
```
