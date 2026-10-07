<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/EINK.php';

/**
 * Bring Liste
 * Eine Bring!-Einkaufsliste als Instanz mit Variablen, eigener Kachel
 * und Benachrichtigungen.
 *
 * Autor: Armin Frohwerk
 */
class BringListe extends IPSModuleStrict
{
    private const BENACHRICHTIGUNGEN = [
        1 => EINK::NOTIFY_GOING_SHOPPING,
        2 => EINK::NOTIFY_SHOPPING_DONE,
        3 => EINK::NOTIFY_CHANGED_LIST
    ];

    private ?array $UebersetzungCache = null;
    private string $LetzterFehler = '';

    public function Create(): void
    {
        parent::Create();


        $this->RegisterPropertyString('ListUuid', '');
        $this->RegisterPropertyString('ListName', '');
        $this->RegisterPropertyInteger('Intervall', 300);
        $this->RegisterPropertyInteger('AutoBenachrichtigung', 0);
        $this->RegisterPropertyBoolean('Kachel', true);
        $this->RegisterPropertyInteger('TileTheme', 0);         // 0 = Symcon-Design, 1 = Dunkel, 2 = Hell
        $this->RegisterPropertyBoolean('ZuletztAnzeigen', true);
        $this->RegisterPropertyInteger('ZuletztMax', 12);

        $this->RegisterAttributeString('Artikel', '{"purchase":[],"recently":[]}');
        $this->RegisterAttributeString('Sprache', '');
        $this->RegisterAttributeInteger('KatalogStand', 0);
        $this->RegisterAttributeInteger('KatalogVersuch', 0);
        $this->RegisterAttributeString('Uebersetzung', '{}');
        $this->RegisterAttributeInteger('ParentID', 0);

        $this->RegisterTimer('Aktualisieren', 0, 'EINK_Update($_IPS[\'TARGET\']);');
        $this->RegisterTimer('Katalog', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], "Katalog", true);');
        $this->RegisterTimer('Benachrichtigen', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], "AutoBenachrichtigung", true);');
    }

    /**
     * Übergeordnete Instanz: Bring Konto
     */
    public function GetCompatibleParents(): string
    {
        return json_encode([
            'type'      => 'connect',
            'moduleIDs' => [EINK::MODUL_KONTO]
        ]);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->SetTimerInterval('Aktualisieren', 0);
        $this->SetTimerInterval('Benachrichtigen', 0);
        $this->SetTimerInterval('Katalog', 0);

        $this->VariablenAnlegen();
        $this->SetVisualizationType($this->ReadPropertyBoolean('Kachel') ? 1 : 0);

        if (IPS_GetKernelRunlevel() != KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            return;
        }

        $this->RegisterMessage($this->InstanceID, FM_CONNECT);
        $this->RegisterMessage($this->InstanceID, FM_DISCONNECT);
        $this->ParentBeobachten();

        if ($this->ReadPropertyString('ListUuid') === '') {
            $this->SetStatus(EINK::STATUS_KEINE_LISTE);
            return;
        }

        $this->Start();
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        switch ($Message) {
            case IPS_KERNELSTARTED:
                $this->UnregisterMessage(0, IPS_KERNELSTARTED);
                $this->ApplyChanges();
                break;
            case FM_CONNECT:
            case FM_DISCONNECT:
                $this->ParentBeobachten();
                $this->Start();
                break;
            case IM_CHANGESTATUS:
                if ($SenderID == $this->ReadAttributeInteger('ParentID')) {
                    if ($Data[0] == IS_ACTIVE) {
                        $this->Start();
                    } else {
                        $this->SetTimerInterval('Aktualisieren', 0);
                        $this->SetStatus(EINK::STATUS_KEINE_VERBINDUNG);
                    }
                }
                break;
        }
    }

    public function ReceiveData(string $JSONString): string
    {
        return '';
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'Hinzufuegen':
                $this->EingabeHinzufuegen((string) $Value);
                $this->SetValue('Hinzufuegen', '');
                break;
            case 'Benachrichtigung':
                if (isset(self::BENACHRICHTIGUNGEN[(int) $Value])) {
                    $this->SetValue('Benachrichtigung', (int) $Value);
                    $this->SendNotification(self::BENACHRICHTIGUNGEN[(int) $Value]);
                }
                break;
            case 'Dringend':
                if (trim((string) $Value) !== '') {
                    $this->SendUrgentItem(trim((string) $Value));
                }
                $this->SetValue('Dringend', '');
                break;
            case 'Aktualisieren':
                $this->Update();
                break;
            case 'AutoBenachrichtigung':
                $this->SetTimerInterval('Benachrichtigen', 0);
                $this->SendNotification(EINK::NOTIFY_CHANGED_LIST);
                break;
            case 'Kachel':
                $Meldung = $this->KachelAktion((string) $Value);
                if ($Meldung !== '') {
                    $this->KachelMeldung($Meldung);
                }
                break;
            case 'Katalog':
                if ($this->HasActiveParent() && $this->KatalogPruefen()) {
                    $this->VariablenSetzen($this->ArtikelHolen(), false);
                    $this->KachelSenden(true);
                }
                break;
            default:
                throw new Exception('Ungültiger Ident: ' . $Ident);
        }
    }

    public function GetConfigurationForm(): string
    {
        $Form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        $Optionen = [['caption' => '– bitte wählen –', 'value' => '']];
        $Aktuell = $this->ReadPropertyString('ListUuid');
        $Gefunden = false;

        if ($this->HasActiveParent()) {
            $Result = EINK::Response(@$this->SendDataToParent(EINK::Request('GET', 'bringusers/{uuid}/lists')));
            if ($Result['Success'] && is_array($Result['Data'])) {
                foreach ($Result['Data']['lists'] ?? [] as $Liste) {
                    $Uuid = (string) ($Liste['listUuid'] ?? '');
                    if ($Uuid === '') {
                        continue;
                    }
                    $Optionen[] = ['caption' => (string) ($Liste['name'] ?? $Uuid), 'value' => $Uuid];
                    $Gefunden = $Gefunden || ($Uuid === $Aktuell);
                }
            }
        }
        if ($Aktuell !== '' && !$Gefunden) {
            $Name = $this->ReadPropertyString('ListName');
            $Optionen[] = ['caption' => ($Name !== '' ? $Name : $Aktuell), 'value' => $Aktuell];
        }
        $Form['elements'][0]['options'] = $Optionen;
        return json_encode($Form);
    }

    public function GetVisualizationTile(): string
    {
        $HTML = file_get_contents(__DIR__ . '/tile.html');
        $Daten = $this->KachelDaten();
        $Daten['katalog'] = $this->KachelKatalog();
        $Daten = json_encode($Daten, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        return $HTML . '<script>handleMessage(' . json_encode($Daten, JSON_HEX_TAG) . ');</script>';
    }

    // ------------------------------------------------------------------
    // Öffentliche Funktionen (EINK_...)
    // ------------------------------------------------------------------

    /**
     * Lädt die Liste neu aus der Cloud.
     */
    public function Update(): bool
    {
        $Uuid = $this->ReadPropertyString('ListUuid');
        if ($Uuid === '') {
            $this->SetStatus(EINK::STATUS_KEINE_LISTE);
            return false;
        }
        if (!$this->HasActiveParent()) {
            $this->SetStatus(EINK::STATUS_KEINE_VERBINDUNG);
            return false;
        }

        $KatalogNeu = $this->KatalogPruefen();

        $Result = $this->Senden('GET', 'bringlists/' . $Uuid);
        if (!$Result['Success'] || !is_array($Result['Data'])) {
            $this->SetStatus($Result['Code'] == 404 ? EINK::STATUS_KEINE_LISTE : EINK::STATUS_KEINE_VERBINDUNG);
            return false;
        }

        $Wurzel = isset($Result['Data']['items']) && is_array($Result['Data']['items']) ? $Result['Data']['items'] : $Result['Data'];
        $Artikel = [
            'purchase' => $this->ArtikelLesen($Wurzel['purchase'] ?? []),
            'recently' => $this->ArtikelLesen($Wurzel['recently'] ?? [])
        ];
        $this->WriteAttributeString('Artikel', json_encode($Artikel));

        $this->VariablenSetzen($Artikel);
        $this->KachelSenden($KatalogNeu);

        if ($this->GetStatus() != IS_ACTIVE) {
            $this->SetStatus(IS_ACTIVE);
        }
        return true;
    }

    /**
     * Liefert die aktuelle Liste: ['purchase' => [...], 'recently' => [...]]
     * Jeder Artikel: ['name' => Anzeigename, 'key' => Bring!-Artikel-ID, 'spec' => Beschreibung]
     */
    public function GetItems(): array
    {
        $Artikel = $this->ArtikelHolen();
        $Ergebnis = [];
        foreach (['purchase', 'recently'] as $Bereich) {
            $Ergebnis[$Bereich] = [];
            foreach ($Artikel[$Bereich] as $A) {
                $Ergebnis[$Bereich][] = ['name' => $this->Anzeige($A['key']), 'key' => $A['key'], 'spec' => $A['spec']];
            }
        }
        return $Ergebnis;
    }

    /**
     * Setzt einen Artikel auf die Liste (oder ändert seine Beschreibung).
     */
    public function AddItem(string $Name, string $Beschreibung): bool
    {
        $Name = trim($Name);
        if ($Name === '') {
            return false;
        }
        $Key = $this->ArtikelFinden($Name) ?? $this->Schluessel($Name);
        return $this->Aendern(['purchase' => $Key, 'recently' => '', 'specification' => trim($Beschreibung), 'remove' => '']);
    }

    /**
     * Hakt einen Artikel ab (wandert nach "Zuletzt verwendet").
     */
    public function CompleteItem(string $Name): bool
    {
        $Key = $this->ArtikelFinden($Name, 'purchase');
        if ($Key === null) {
            trigger_error('Artikel "' . $Name . '" steht nicht auf der Liste', E_USER_NOTICE);
            return false;
        }
        return $this->Aendern(['purchase' => '', 'recently' => $Key, 'specification' => $this->Beschreibung($Key), 'remove' => '']);
    }

    /**
     * Entfernt einen Artikel komplett (auch aus "Zuletzt verwendet").
     */
    public function RemoveItem(string $Name): bool
    {
        $Key = $this->ArtikelFinden($Name);
        if ($Key === null) {
            trigger_error('Artikel "' . $Name . '" nicht gefunden', E_USER_NOTICE);
            return false;
        }
        return $this->Aendern(['purchase' => '', 'recently' => '', 'specification' => '', 'remove' => $Key]);
    }

    /**
     * Sendet eine Benachrichtigung an alle Mitglieder der Liste.
     * Typen: GOING_SHOPPING, SHOPPING_DONE, CHANGED_LIST
     */
    public function SendNotification(string $Typ): bool
    {
        $Typ = strtoupper(trim($Typ));
        if (!in_array($Typ, self::BENACHRICHTIGUNGEN, true)) {
            trigger_error('Unbekannter Benachrichtigungstyp: ' . $Typ, E_USER_WARNING);
            return false;
        }
        return $this->Benachrichtigen($Typ, []);
    }

    /**
     * Sendet "Dringend benötigt: <Artikel>" an alle Mitglieder der Liste.
     */
    public function SendUrgentItem(string $Name): bool
    {
        $Name = trim($Name);
        if ($Name === '') {
            return false;
        }
        return $this->Benachrichtigen(EINK::NOTIFY_URGENT, [$Name]);
    }

    /**
     * Kachel-Daten für die Bring Übersicht (JSON).
     */
    public function GetTileData(bool $MitKatalog): string
    {
        $Daten = $this->KachelDaten();
        $Daten['name'] = IPS_GetName($this->InstanceID);
        $Daten['anzahl'] = count($this->ArtikelHolen()['purchase']);
        if ($MitKatalog) {
            $Daten['katalog'] = $this->KachelKatalog();
        }
        return json_encode($Daten);
    }

    /**
     * Führt eine Kachel-Aktion aus (für die Bring Übersicht) und liefert ggf. eine Meldung.
     */
    public function TileAction(string $Aktion): string
    {
        return $this->KachelAktion($Aktion);
    }

    // ------------------------------------------------------------------
    // Intern
    // ------------------------------------------------------------------

    private function Start(): void
    {
        $this->SetTimerInterval('Aktualisieren', 0);
        if ($this->ReadPropertyString('ListUuid') === '') {
            $this->SetStatus(EINK::STATUS_KEINE_LISTE);
            return;
        }
        if (!$this->HasActiveParent()) {
            $this->SetStatus(EINK::STATUS_KEINE_VERBINDUNG);
            return;
        }
        $this->KatalogPruefen(true);
        $this->Update();
        $this->SetTimerInterval('Katalog', 3600 * 1000);
        $Intervall = $this->ReadPropertyInteger('Intervall');
        if ($Intervall > 0) {
            $this->SetTimerInterval('Aktualisieren', max(30, $Intervall) * 1000);
        }
    }

    private function ParentBeobachten(): void
    {
        $Alt = $this->ReadAttributeInteger('ParentID');
        $Neu = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        if ($Alt == $Neu) {
            if ($Neu > 0) {
                $this->RegisterMessage($Neu, IM_CHANGESTATUS);
            }
            return;
        }
        if ($Alt > 0 && IPS_InstanceExists($Alt)) {
            $this->UnregisterMessage($Alt, IM_CHANGESTATUS);
        }
        if ($Neu > 0) {
            $this->RegisterMessage($Neu, IM_CHANGESTATUS);
        }
        $this->WriteAttributeInteger('ParentID', $Neu);
    }

    private function Senden(string $Method, string $Endpoint, array $Body = [], string $Type = 'none'): array
    {
        if (!$this->HasActiveParent()) {
            return ['Success' => false, 'Code' => 0, 'Data' => null, 'Error' => 'Konto nicht verbunden'];
        }
        $Result = EINK::Response(@$this->SendDataToParent(EINK::Request($Method, $Endpoint, $Body, $Type)));
        if (!$Result['Success']) {
            $this->SendDebug('Fehler', $Method . ' ' . $Endpoint . ': ' . $Result['Error'], 0);
        }
        return $Result;
    }

    private function Aendern(array $Felder): bool
    {
        $Felder['sender'] = '{publicUuid}';
        $Result = $this->Senden('PUT', 'bringlists/' . $this->ReadPropertyString('ListUuid'), $Felder, 'form');
        if (!$Result['Success']) {
            trigger_error('Liste konnte nicht geändert werden (' . $Result['Error'] . ')', E_USER_WARNING);
            return false;
        }
        $this->Update();
        $Verzoegerung = $this->ReadPropertyInteger('AutoBenachrichtigung');
        if ($Verzoegerung > 0) {
            $this->SetTimerInterval('Benachrichtigen', $Verzoegerung * 1000);
        }
        return true;
    }

    private function Benachrichtigen(string $Typ, array $Argumente): bool
    {
        $Result = $this->Senden('POST', 'bringnotifications/lists/' . $this->ReadPropertyString('ListUuid'), [
            'arguments'            => $Argumente,
            'listNotificationType' => $Typ,
            'senderPublicUserUuid' => '{publicUuid}'
        ], 'json');
        if (!$Result['Success']) {
            $this->LetzterFehler = $Result['Error'] !== '' ? $Result['Error'] : 'unbekannter Fehler';
            if (is_array($Result['Data']) && isset($Result['Data']['message'])) {
                $this->LetzterFehler .= ' – ' . $Result['Data']['message'];
            }
            $this->LogMessage('Bring!-Benachrichtigung ' . $Typ . ' fehlgeschlagen: ' . $this->LetzterFehler, KL_WARNING);
            return false;
        }
        $this->SendDebug('Benachrichtigung', $Typ . ' gesendet (HTTP ' . $Result['Code'] . ')', 0);
        return true;
    }

    /** Liefert false, wenn mindestens ein Artikel nicht gespeichert werden konnte. */
    private function EingabeHinzufuegen(string $Text): bool
    {
        $Ok = true;
        foreach (preg_split('/[\r\n;]+/', $Text) as $Zeile) {
            $Teile = explode(',', $Zeile, 2);
            $Name = trim($Teile[0]);
            if ($Name !== '') {
                $Ok = $this->AddItem($Name, trim($Teile[1] ?? '')) && $Ok;
            }
        }
        return $Ok;
    }

    private function KachelAktion(string $JSON): string
    {
        $A = json_decode($JSON, true);
        if (!is_array($A)) {
            return '';
        }
        $Key = (string) ($A['key'] ?? '');
        $Ok = true; // false: Änderung nicht gespeichert, die Kachel zeigt sie aber schon an
        switch ($A['aktion'] ?? '') {
            case 'hinzufuegen':
                $Ok = $this->EingabeHinzufuegen((string) ($A['text'] ?? ''));
                break;
            case 'abhaken':
                $Ok = $this->Aendern(['purchase' => '', 'recently' => $Key, 'specification' => $this->Beschreibung($Key), 'remove' => '']);
                break;
            case 'wieder':
                $Ok = $this->Aendern(['purchase' => $Key, 'recently' => '', 'specification' => $this->Beschreibung($Key), 'remove' => '']);
                break;
            case 'beschreibung':
                $Ok = $this->Aendern(['purchase' => $Key, 'recently' => '', 'specification' => trim((string) ($A['spec'] ?? '')), 'remove' => '']);
                break;
            case 'entfernen':
                $Ok = $this->Aendern(['purchase' => '', 'recently' => '', 'specification' => '', 'remove' => $Key]);
                break;
            case 'benachrichtigen':
                $Texte = [
                    EINK::NOTIFY_GOING_SHOPPING => 'Gehe einkaufen',
                    EINK::NOTIFY_SHOPPING_DONE  => 'Einkauf erledigt',
                    EINK::NOTIFY_CHANGED_LIST   => 'Liste geändert'
                ];
                $Typ = (string) ($A['typ'] ?? '');
                $Ok = $this->SendNotification($Typ);
                return $Ok
                    ? '„' . ($Texte[$Typ] ?? $Typ) . '“ an die anderen Mitglieder der Liste gesendet.'
                    : 'Benachrichtigung fehlgeschlagen: ' . $this->LetzterFehler;
            case 'artikel':
                $Key = (string) ($A['key'] ?? '');
                if ($Key !== '') {
                    $Ok = $this->Aendern(['purchase' => $Key, 'recently' => '', 'specification' => trim((string) ($A['spec'] ?? '')), 'remove' => '']);
                }
                break;
            case 'aktualisieren':
                $this->Update();
                break;
        }
        if (!$Ok) {
            // Kachel hat die Änderung schon angezeigt: echten Stand zurückschicken
            $this->KachelSenden();
            return 'Änderung konnte nicht gespeichert werden – bitte später erneut versuchen.';
        }
        return '';
    }

    private function ArtikelLesen(array $Liste): array
    {
        $Ergebnis = [];
        foreach ($Liste as $A) {
            if (!is_array($A)) {
                continue;
            }
            $Key = (string) ($A['itemId'] ?? $A['name'] ?? '');
            if ($Key === '') {
                continue;
            }
            $Ergebnis[] = ['key' => $Key, 'spec' => trim((string) ($A['specification'] ?? ''))];
        }
        return $Ergebnis;
    }

    private function ArtikelHolen(): array
    {
        $Artikel = json_decode($this->ReadAttributeString('Artikel'), true);
        if (!is_array($Artikel)) {
            $Artikel = [];
        }
        return $Artikel + ['purchase' => [], 'recently' => []];
    }

    /**
     * Sucht einen Artikel nach ID oder Anzeigename (ohne Groß-/Kleinschreibung).
     */
    private function ArtikelFinden(string $Name, string $NurBereich = ''): ?string
    {
        $Suche = mb_strtolower(trim($Name));
        $Artikel = $this->ArtikelHolen();
        foreach (['purchase', 'recently'] as $Bereich) {
            if ($NurBereich !== '' && $Bereich !== $NurBereich) {
                continue;
            }
            foreach ($Artikel[$Bereich] as $A) {
                if (mb_strtolower($A['key']) === $Suche || mb_strtolower($this->Anzeige($A['key'])) === $Suche) {
                    return $A['key'];
                }
            }
        }
        return null;
    }

    private function Beschreibung(string $Key): string
    {
        $Artikel = $this->ArtikelHolen();
        foreach (['purchase', 'recently'] as $Bereich) {
            foreach ($Artikel[$Bereich] as $A) {
                if ($A['key'] === $Key) {
                    return $A['spec'];
                }
            }
        }
        return '';
    }

    // ---------- Sprache / Katalog ----------

    /**
     * Lädt Listensprache und Bring!-Artikelkatalog neu.
     * Schlägt der Abruf fehl, bleibt der bisherige Katalog erhalten.
     * Rückgabe: true = geändert, false = unverändert, null = Abruf fehlgeschlagen
     */
    private function KatalogLaden(): ?bool
    {
        $this->WriteAttributeInteger('KatalogVersuch', time());

        $Sprache = 'de-DE';
        $Result = $this->Senden('GET', 'bringusersettings/{uuid}');
        if ($Result['Success'] && is_array($Result['Data'])) {
            foreach ($Result['Data']['userlistsettings'] ?? [] as $Liste) {
                if (($Liste['listUuid'] ?? '') !== $this->ReadPropertyString('ListUuid')) {
                    continue;
                }
                foreach ($Liste['usersettings'] ?? [] as $Einstellung) {
                    if (($Einstellung['key'] ?? '') === 'listArticleLanguage' && !empty($Einstellung['value'])) {
                        $Sprache = (string) $Einstellung['value'];
                    }
                }
            }
        }

        // Artikelkatalog (Artikel-ID => Anzeigename), auch für Vorschläge in der Kachel
        $Uebersetzung = [];
        $Katalog = $this->Senden('GET', sprintf(EINK::ARTICLES_URL, $Sprache));
        if ($Katalog['Success'] && is_array($Katalog['Data'])) {
            foreach ($Katalog['Data'] as $Key => $Wert) {
                if (is_string($Wert) && $Wert !== '') {
                    $Uebersetzung[(string) $Key] = $Wert;
                }
            }
        }
        if (count($Uebersetzung) == 0) {
            $this->SendDebug('Katalog', 'Abruf fehlgeschlagen (' . $Katalog['Error'] . '), bisheriger Katalog bleibt', 0);
            return null;
        }

        $Alt = $this->ReadAttributeString('Uebersetzung');
        $Neu = json_encode($Uebersetzung);
        $this->WriteAttributeString('Sprache', $Sprache);
        $this->WriteAttributeInteger('KatalogStand', time());
        $this->SendDebug('Katalog', $Sprache . ': ' . count($Uebersetzung) . ' Artikel' . ($Alt === $Neu ? ' (unverändert)' : ' (aktualisiert)'), 0);
        if ($Alt !== $Neu) {
            $this->WriteAttributeString('Uebersetzung', $Neu);
            $this->UebersetzungCache = null;
            return true;
        }
        return false;
    }

    /**
     * Katalog täglich abgleichen; fehlt er, alle 15 Minuten erneut versuchen.
     */
    private function KatalogPruefen(bool $Erzwingen = false): bool
    {
        $Leer = in_array($this->ReadAttributeString('Uebersetzung'), ['', '{}', '[]'], true);
        $Faellig = (time() - $this->ReadAttributeInteger('KatalogStand')) > 86400;
        $Wiederholen = (time() - $this->ReadAttributeInteger('KatalogVersuch')) > 900;
        if ($Erzwingen || ($Leer && $Wiederholen) || ($Faellig && $Wiederholen)) {
            return $this->KatalogLaden() === true;
        }
        return false;
    }

    private function Anzeige(string $Key): string
    {
        if ($this->UebersetzungCache === null) {
            $this->UebersetzungCache = json_decode($this->ReadAttributeString('Uebersetzung'), true) ?: [];
        }
        return $this->UebersetzungCache[$Key] ?? $Key;
    }

    /**
     * Wandelt eine Eingabe in die Bring!-Artikel-ID (Katalog-Schlüssel) um.
     */
    private function Schluessel(string $Name): string
    {
        $Uebersetzung = json_decode($this->ReadAttributeString('Uebersetzung'), true) ?: [];
        $Suche = mb_strtolower($Name);
        foreach ($Uebersetzung as $Key => $Wert) {
            if (mb_strtolower($Wert) === $Suche || mb_strtolower((string) $Key) === $Suche) {
                return (string) $Key;
            }
        }
        return mb_strtoupper(mb_substr($Name, 0, 1)) . mb_substr($Name, 1);
    }

    private static function IconName(string $Key): string
    {
        $Name = mb_strtolower($Key);
        $Name = str_replace(['ä', 'ö', 'ü', 'ß', 'é', 'è', 'à'], ['ae', 'oe', 'ue', 'ss', 'e', 'e', 'a'], $Name);
        $Name = preg_replace('/[^a-z0-9]+/', '_', $Name);
        return trim($Name, '_');
    }

    // ---------- Variablen ----------

    private function VariablenAnlegen(): void
    {
        $Text = ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'MULTILINE' => true];
        $Eingabe = ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_INPUT];
        $Zahl = ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'cart-shopping'];
        $Datum = defined('VARIABLE_PRESENTATION_DATE_TIME')
            ? ['PRESENTATION' => VARIABLE_PRESENTATION_DATE_TIME]
            : ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION];

        $this->RegisterVariableString('Liste', 'Einkaufsliste', $Text, 1);
        $this->RegisterVariableInteger('Anzahl', 'Anzahl Artikel', $Zahl, 2);

        $this->RegisterVariableString('Hinzufuegen', 'Artikel hinzufügen', $Eingabe + ['ICON' => 'plus'], 3);
        $this->EnableAction('Hinzufuegen');

        $this->RegisterVariableInteger('Benachrichtigung', 'Benachrichtigung senden', [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'ICON'         => 'bell',
            'OPTIONS'      => json_encode([
                self::Option(1, 'Gehe einkaufen'),
                self::Option(2, 'Einkauf erledigt'),
                self::Option(3, 'Liste geändert')
            ])
        ], 4);
        $this->EnableAction('Benachrichtigung');

        $this->RegisterVariableString('Dringend', 'Dringend benötigt', $Eingabe + ['ICON' => 'triangle-exclamation'], 5);
        $this->EnableAction('Dringend');

        $this->RegisterVariableInteger('Aktualisieren', 'Liste aktualisieren', [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'ICON'         => 'arrows-rotate',
            'OPTIONS'      => json_encode([self::Option(0, 'Aktualisieren')])
        ], 6);
        $this->EnableAction('Aktualisieren');

        $this->RegisterVariableInteger('Zeitpunkt', 'Letzte Aktualisierung', $Datum + ['ICON' => 'clock'], 7);
    }

    private static function Option(int $Wert, string $Text): array
    {
        return [
            'Value'       => $Wert,
            'Caption'     => $Text,
            'IconActive'  => false,
            'IconValue'   => '',
            'ColorActive' => false,
            'ColorValue'  => -1
        ];
    }

    /** $Abgerufen = false: nur neu dargestellt (z. B. neuer Katalog), „Letzte Aktualisierung“ bleibt. */
    private function VariablenSetzen(array $Artikel, bool $Abgerufen = true): void
    {
        $Zeilen = [];
        foreach ($Artikel['purchase'] as $A) {
            $Zeilen[] = '• ' . $this->Anzeige($A['key']) . ($A['spec'] !== '' ? ' – ' . $A['spec'] : '');
        }
        $Text = count($Zeilen) ? implode("\n", $Zeilen) : 'Die Liste ist leer.';
        if ($this->GetValue('Liste') !== $Text) {
            $this->SetValue('Liste', $Text);
        }
        if ($this->GetValue('Anzahl') !== count($Zeilen)) {
            $this->SetValue('Anzahl', count($Zeilen));
        }
        if ($Abgerufen) {
            $this->SetValue('Zeitpunkt', time());
        }
    }

    // ---------- Kachel ----------

    private function KachelDaten(): array
    {
        $Artikel = $this->ArtikelHolen();
        $Daten = [
            'titel'    => $this->ReadPropertyString('ListName') !== '' ? $this->ReadPropertyString('ListName') : IPS_GetName($this->InstanceID),
            'bilder'   => EINK::IMAGES_URL,
            'theme'    => $this->ReadPropertyInteger('TileTheme'),
            'purchase' => [],
            'recently' => []
        ];
        foreach ($Artikel['purchase'] as $A) {
            $Daten['purchase'][] = $this->KachelArtikel($A);
        }
        if ($this->ReadPropertyBoolean('ZuletztAnzeigen')) {
            $Max = max(0, $this->ReadPropertyInteger('ZuletztMax'));
            foreach (array_slice($Artikel['recently'], 0, $Max) as $A) {
                $Daten['recently'][] = $this->KachelArtikel($A);
            }
        }
        return $Daten;
    }

    private function KachelArtikel(array $A): array
    {
        $Anzeige = $this->Anzeige($A['key']);
        return [
            'key'   => $A['key'],
            'name'  => $Anzeige,
            'spec'  => $A['spec'],
            'icon'  => self::IconName($A['key']),
            'buchstabe' => self::IconName(mb_substr($A['key'], 0, 1))
        ];
    }

    /**
     * Vorschlagsliste für die Kachel: Katalog + eigene Artikel. Je Eintrag [ID, Anzeigename, Symbol, Buchstabe]
     */
    private function KachelKatalog(): array
    {
        $Liste = [];
        $Gesehen = [];
        $Uebersetzung = json_decode($this->ReadAttributeString('Uebersetzung'), true) ?: [];
        foreach ($Uebersetzung as $Key => $Wert) {
            $Key = (string) $Key;
            if (strpos($Key, '&') !== false || strpos($Wert, '&') !== false) {
                continue; // Kategorien
            }
            $Name = mb_strtolower($Wert);
            if (isset($Gesehen[$Name])) {
                continue;
            }
            $Gesehen[$Name] = true;
            $Liste[] = [$Key, $Wert, self::IconName($Key), self::IconName(mb_substr($Key, 0, 1))];
        }
        $Artikel = $this->ArtikelHolen();
        foreach (array_merge($Artikel['purchase'], $Artikel['recently']) as $A) {
            $Wert = $this->Anzeige($A['key']);
            $Name = mb_strtolower($Wert);
            if (isset($Gesehen[$Name])) {
                continue;
            }
            $Gesehen[$Name] = true;
            $Liste[] = [$A['key'], $Wert, self::IconName($A['key']), self::IconName(mb_substr($A['key'], 0, 1))];
        }
        return $Liste;
    }

    private function KachelMeldung(string $Text): void
    {
        if ($this->ReadPropertyBoolean('Kachel')) {
            $this->UpdateVisualizationValue(json_encode(['meldung' => $Text]));
        }
    }

    private function KachelSenden(bool $MitKatalog = false): void
    {
        if ($this->ReadPropertyBoolean('Kachel')) {
            $Daten = $this->KachelDaten();
            if ($MitKatalog) {
                $Daten['katalog'] = $this->KachelKatalog();
            }
            $this->UpdateVisualizationValue(json_encode($Daten));
        }
    }

    /**
     * Lädt den Bring!-Artikelkatalog sofort neu (sonst automatisch einmal täglich).
     */
    public function ReloadCatalog(): bool
    {
        $Ergebnis = $this->KatalogLaden();
        if ($Ergebnis === null) {
            return false;
        }
        if ($Ergebnis) {
            $this->VariablenSetzen($this->ArtikelHolen(), false);
            $this->KachelSenden(true);
        }
        return true;
    }
}
