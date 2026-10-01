# Bring Liste

Eine Bring!-Einkaufsliste als Instanz in IP-Symcon.

## Einstellungen

| Einstellung | Beschreibung |
| :---------- | :----------- |
| Liste | Auswahl der Liste aus dem Konto (wird vom Konfigurator gesetzt) |
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
- `⋮` oder Rechtsklick → Beschreibung ändern oder Artikel entfernen
- Eingabefeld oben: Beim Tippen erscheinen passende Artikel mit Symbol (Bring!-Katalog und eigene Artikel). Antippen oder mit Pfeiltasten + Enter hinzufügen. Eine Beschreibung nach einem Komma wird übernommen, z. B. `Milch, 2 Liter`
- Unten: Benachrichtigungen an alle anderen Mitglieder der Liste (der Absender selbst bekommt keine Nachricht). Unter den Artikeln erscheint, ob das Senden geklappt hat

Der Bring!-Artikelkatalog (Anzeigenamen und Vorschläge) wird beim Start und danach automatisch einmal täglich abgeglichen. Die Artikel-Symbole werden direkt im Browser von Bring! geladen. Eigene Artikel ohne Symbol erhalten den Anfangsbuchstaben.

## PHP-Befehle

```php
bool  EINK_Update(int $InstanzID);                                   // Liste neu laden
array EINK_GetItems(int $InstanzID);                                 // ['purchase' => [...], 'recently' => [...]]
bool  EINK_AddItem(int $InstanzID, string $Name, string $Beschreibung);
bool  EINK_CompleteItem(int $InstanzID, string $Name);               // abhaken
bool  EINK_RemoveItem(int $InstanzID, string $Name);                 // ganz entfernen
bool  EINK_SendNotification(int $InstanzID, string $Typ);            // GOING_SHOPPING, SHOPPING_DONE, CHANGED_LIST
bool  EINK_SendUrgentItem(int $InstanzID, string $Name);
bool  EINK_ReloadCatalog(int $InstanzID);                            // Artikelkatalog sofort neu laden

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
