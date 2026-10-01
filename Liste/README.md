# Bring Liste

Eine Bring!-Einkaufsliste als Instanz in IP-Symcon.

## Einstellungen

| Einstellung | Beschreibung |
| :---------- | :----------- |
| Liste | Auswahl der Liste aus dem Konto (wird vom Konfigurator gesetzt) |
| Anzeigename in der Kachel | Überschrift der Kachel (leer = Instanzname) |
| Liste aktualisieren alle | Abfrageintervall in Sekunden (Standard 300, 0 = aus, mindestens 30) |
| „Liste geändert“ senden | Schickt nach Änderungen aus Symcon automatisch eine Benachrichtigung – sofort oder verzögert, damit mehrere Änderungen nur eine Nachricht auslösen |
| Eigene Kachel verwenden | Zeigt die Liste als Kachel mit Artikel-Symbolen |
| „Zuletzt verwendet“ anzeigen / Anzahl | Zuletzt gekaufte Artikel zum schnellen Wieder-Hinzufügen |

## Variablen

| Variable | Beschreibung |
| :------- | :----------- |
| Einkaufsliste | Alle offenen Artikel als Text |
| Anzahl Artikel | Anzahl offener Artikel |
| Artikel hinzufügen | Eingabe: `Milch` oder `Milch, 2 Liter`. Mehrere Artikel mit `;` oder Zeilenumbruch trennen |
| Benachrichtigung senden | Gehe einkaufen / Einkauf erledigt / Liste geändert |
| Dringend benötigt | Sendet „dringend benötigt: …“ an alle Mitglieder |
| Liste aktualisieren | Lädt die Liste sofort neu |
| Letzte Aktualisierung | Zeitpunkt des letzten Abrufs |

## Kachel

- Artikel antippen → abhaken (wandert nach „Zuletzt verwendet“)
- „Zuletzt verwendet“ antippen → wieder auf die Liste
- `⋯` → Beschreibung ändern oder Artikel entfernen
- Eingabefeld oben: `Artikel, Beschreibung` + Enter
- Unten: Benachrichtigungen an alle Mitglieder

Die Artikel-Symbole werden direkt im Browser von Bring! geladen. Eigene Artikel ohne Symbol erhalten den Anfangsbuchstaben.

## PHP-Befehle

```php
bool  EINK_Update(int $InstanzID);                                   // Liste neu laden
array EINK_GetItems(int $InstanzID);                                 // ['purchase' => [...], 'recently' => [...]]
bool  EINK_AddItem(int $InstanzID, string $Name, string $Beschreibung);
bool  EINK_CompleteItem(int $InstanzID, string $Name);               // abhaken
bool  EINK_RemoveItem(int $InstanzID, string $Name);                 // ganz entfernen
bool  EINK_SendNotification(int $InstanzID, string $Typ);            // GOING_SHOPPING, SHOPPING_DONE, CHANGED_LIST
bool  EINK_SendUrgentItem(int $InstanzID, string $Name);

// Beispiel
EINK_AddItem(12345, 'Milch', '2 Liter');
EINK_CompleteItem(12345, 'Milch');
```

## Status

| Code | Bedeutung |
| :--: | :-------- |
| 102 | Liste ist verbunden |
| 204 | Keine Verbindung zum Konto |
| 205 | Keine Liste ausgewählt oder Liste existiert nicht mehr |
