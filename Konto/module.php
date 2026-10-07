<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/EINK.php';

/**
 * Bring Konto
 * Meldet sich am Bring!-Konto an, verwaltet die Tokens und leitet
 * alle Anfragen der Listen- und Konfigurator-Instanzen an die Cloud weiter.
 *
 * Autor: Armin Frohwerk
 */
class BringKonto extends IPSModuleStrict
{
    // Nach abgelehnter Anmeldung warten: 15 Minuten, dann doppelt so lange, höchstens 6 Stunden
    private const LOGIN_WARTEN_START = 900;
    private const LOGIN_WARTEN_MAX = 21600;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyBoolean('HinweisBestaetigt', false);
        $this->RegisterPropertyBoolean('Aktiv', true);
        $this->RegisterPropertyString('EMail', '');
        $this->RegisterPropertyString('Passwort', '');
        $this->RegisterPropertyInteger('NeueListen', 0);

        $this->RegisterAttributeString('Uuid', '');
        $this->RegisterAttributeString('PublicUuid', '');
        $this->RegisterAttributeString('Name', '');
        $this->RegisterAttributeString('AccessToken', '');
        $this->RegisterAttributeString('RefreshToken', '');
        $this->RegisterAttributeInteger('TokenAblauf', 0);
        $this->RegisterAttributeString('LoginHash', '');
        $this->RegisterAttributeString('BringListen', '');
        $this->RegisterAttributeString('Gemeldet', '[]');
        $this->RegisterAttributeInteger('LoginFehler', 0);
        $this->RegisterAttributeInteger('LoginSperreBis', 0);

        $this->RegisterTimer('TokenRefresh', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], "TokenRefresh", true);');
        $this->RegisterTimer('ListenPruefen', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], "ListenPruefen", true);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->SetTimerInterval('TokenRefresh', 0);
        $this->SetTimerInterval('ListenPruefen', 0);

        $this->RegisterVariableString('NeueListen', 'Neue Listen in Bring!', ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'list-check'], 1);

        if (IPS_GetKernelRunlevel() != KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            return;
        }

        if (!$this->Pruefen()) {
            return;
        }

        // „Übernehmen“ startet sofort einen neuen Anmeldeversuch
        $this->LoginWartezeitBeenden();

        // Zugangsdaten geändert? Dann alte Tokens verwerfen.
        $Hash = md5($this->ReadPropertyString('EMail') . '|' . $this->ReadPropertyString('Passwort'));
        if ($Hash !== $this->ReadAttributeString('LoginHash')) {
            $this->TokensLoeschen();
        }

        if ($this->Verbinden()) {
            $this->ListenAbgleichen();
        }
        $this->SetTimerInterval('ListenPruefen', 15 * 60 * 1000);
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message == IPS_KERNELSTARTED) {
            $this->UnregisterMessage(0, IPS_KERNELSTARTED);
            $this->ApplyChanges();
        }
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'TokenRefresh':
                if ($this->Pruefen()) {
                    $this->Verbinden();
                }
                break;
            case 'ListenPruefen':
                $this->ListenAbgleichen();
                break;
            default:
                throw new Exception('Ungültiger Ident: ' . $Ident);
        }
    }

    public function GetConfigurationForm(): string
    {
        $Form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        $Name = $this->ReadAttributeString('Name');
        $Ablauf = $this->ReadAttributeInteger('TokenAblauf');
        $Info = 'Nicht angemeldet';
        if ($this->ReadAttributeString('AccessToken') !== '') {
            $Info = 'Angemeldet als ' . ($Name !== '' ? $Name : $this->ReadPropertyString('EMail'))
                . ' – Token gültig bis ' . date('d.m.Y H:i', $Ablauf);
        }
        foreach ($Form['actions'] as &$Element) {
            if (($Element['name'] ?? '') === 'Kontoinfo') {
                $Element['caption'] = $Info;
            }
        }
        return json_encode($Form);
    }

    /**
     * Meldet sich neu an (z. B. über den Button "Verbindung testen").
     */
    public function Login(): bool
    {
        if (!$this->Pruefen()) {
            return false;
        }
        if (!$this->Sperren()) {
            return false;
        }
        try {
            $this->TokensLoeschen();
            $Ok = $this->Anmelden(true);
        } finally {
            $this->Freigeben();
        }
        $this->ReloadForm();
        return $Ok;
    }

    /**
     * Liefert alle Listen des Kontos.
     */
    public function GetLists(): array
    {
        $Result = $this->Anfrage('GET', 'bringusers/{uuid}/lists');
        if (!$Result['Success'] || !is_array($Result['Data'])) {
            return [];
        }
        return $Result['Data']['lists'] ?? [];
    }

    /**
     * Gleicht die Listen der Bring!-App mit den Bring Listen in Symcon ab.
     * Liefert ['neu' => [Namen], 'geloescht' => [Instanznamen]]
     */
    public function CheckLists(): array
    {
        $this->ListenAbgleichen();
        return $this->HinweiseBerechnen();
    }

    /**
     * Hinweise zu neuen/gelöschten Listen (für die Bring Übersicht), als JSON.
     */
    public function GetListHints(): string
    {
        return json_encode($this->HinweiseBerechnen());
    }

    /**
     * Anfragen der Kind-Instanzen.
     */
    public function ForwardData(string $JSONString): string
    {
        $Data = json_decode($JSONString, true);
        if (!is_array($Data)) {
            return json_encode(['Success' => false, 'Code' => 0, 'Data' => null, 'Error' => 'Ungültige Anfrage']);
        }
        $Result = $this->Anfrage(
            (string) ($Data['Method'] ?? 'GET'),
            (string) ($Data['Endpoint'] ?? ''),
            (array) ($Data['Body'] ?? []),
            (string) ($Data['Type'] ?? 'none')
        );
        return json_encode($Result);
    }

    // ------------------------------------------------------------------
    // Intern
    // ------------------------------------------------------------------

    private function ListenAbgleichen(): void
    {
        if (!$this->Pruefen()) {
            return;
        }
        $Result = $this->Anfrage('GET', 'bringusers/{uuid}/lists');
        if (!$Result['Success'] || !is_array($Result['Data']) || !isset($Result['Data']['lists'])) {
            $this->SendDebug('Listenabgleich', 'Listen konnten nicht geladen werden', 0);
            return;
        }
        $Bring = [];
        foreach ($Result['Data']['lists'] as $Liste) {
            if (!empty($Liste['listUuid'])) {
                $Bring[(string) $Liste['listUuid']] = (string) ($Liste['name'] ?? $Liste['listUuid']);
            }
        }

        $this->WriteAttributeString('BringListen', json_encode($Bring));
        $Symcon = $this->SymconListen();

        $Neu = array_diff_key($Bring, $Symcon);
        $Geloescht = array_diff_key($Symcon, $Bring);

        // Neue Listen ggf. automatisch anlegen
        if (count($Neu) && $this->ReadPropertyInteger('NeueListen') == 1) {
            $Ort = count($Symcon) ? IPS_GetParent(reset($Symcon)) : 0;
            foreach ($Neu as $Uuid => $Name) {
                $ID = IPS_CreateInstance(EINK::MODUL_LISTE);
                IPS_SetName($ID, $Name);
                IPS_SetParent($ID, $Ort);
                if (IPS_GetInstance($ID)['ConnectionID'] != $this->InstanceID) {
                    if (IPS_GetInstance($ID)['ConnectionID'] > 0) {
                        IPS_DisconnectInstance($ID);
                    }
                    IPS_ConnectInstance($ID, $this->InstanceID);
                }
                IPS_SetProperty($ID, 'ListUuid', $Uuid);
                IPS_SetProperty($ID, 'ListName', $Name);
                IPS_ApplyChanges($ID);
                $this->LogMessage('Neue Bring!-Liste „' . $Name . '“ automatisch als Instanz angelegt (#' . $ID . ')', KL_MESSAGE);
            }
            $Neu = [];
        }

        // Jede Änderung nur einmal ins Meldungsfenster schreiben
        $Gemeldet = json_decode($this->ReadAttributeString('Gemeldet'), true) ?: [];
        $Aktuell = [];
        foreach ($Neu as $Uuid => $Name) {
            $Aktuell[] = 'neu:' . $Uuid;
            if (!in_array('neu:' . $Uuid, $Gemeldet, true)) {
                $this->LogMessage('Neue Bring!-Liste „' . $Name . '“ – bitte im Bring Konfigurator anlegen.', KL_WARNING);
            }
        }
        foreach ($Geloescht as $Uuid => $ID) {
            $Aktuell[] = 'weg:' . $Uuid;
            if (!in_array('weg:' . $Uuid, $Gemeldet, true)) {
                $this->LogMessage('Bring!-Liste der Instanz „' . IPS_GetName($ID) . '“ (#' . $ID . ') gibt es in Bring! nicht mehr.', KL_WARNING);
            }
        }
        $this->WriteAttributeString('Gemeldet', json_encode($Aktuell));

        $Hinweise = $this->HinweiseBerechnen();
        $Text = count($Hinweise['neu']) ? implode(', ', $Hinweise['neu']) : '';
        if ($this->GetValue('NeueListen') !== $Text) {
            $this->SetValue('NeueListen', $Text);
        }
        $this->SendDebug('Listenabgleich', count($Bring) . ' Listen in Bring!, neu: ' . count($Hinweise['neu']) . ', gelöscht: ' . count($Hinweise['geloescht']), 0);
    }

    private function SymconListen(): array
    {
        $Symcon = [];
        foreach (IPS_GetInstanceListByModuleID(EINK::MODUL_LISTE) as $ID) {
            if (IPS_GetInstance($ID)['ConnectionID'] == $this->InstanceID) {
                $Uuid = (string) IPS_GetProperty($ID, 'ListUuid');
                if ($Uuid !== '') {
                    $Symcon[$Uuid] = $ID;
                }
            }
        }
        return $Symcon;
    }

    /**
     * Vergleicht die zuletzt geladenen Bring!-Listen mit den aktuellen Instanzen (ohne Cloud-Abruf).
     */
    private function HinweiseBerechnen(): array
    {
        $Bring = json_decode($this->ReadAttributeString('BringListen'), true);
        if (!is_array($Bring)) {
            return ['neu' => [], 'geloescht' => []];
        }
        $Symcon = $this->SymconListen();
        return [
            'neu'       => array_values(array_diff_key($Bring, $Symcon)),
            'geloescht' => array_values(array_map('IPS_GetName', array_diff_key($Symcon, $Bring)))
        ];
    }

    /**
     * Prüft Hinweis, Aktiv-Schalter und Zugangsdaten und setzt den Status.
     */
    private function Pruefen(): bool
    {
        if (!$this->ReadPropertyBoolean('HinweisBestaetigt')) {
            $this->SetStatus(EINK::STATUS_HINWEIS);
            return false;
        }
        if (!$this->ReadPropertyBoolean('Aktiv')) {
            $this->SetStatus(IS_INACTIVE);
            return false;
        }
        if ($this->ReadPropertyString('EMail') === '' || $this->ReadPropertyString('Passwort') === '') {
            $this->SetStatus(EINK::STATUS_ZUGANG_FEHLT);
            return false;
        }
        return true;
    }

    /**
     * Stellt sicher, dass ein gültiger Token vorliegt.
     * Gesperrt, damit nicht zwei Threads gleichzeitig erneuern und der zweite den frischen Token verwirft.
     */
    private function Verbinden(): bool
    {
        if (!$this->Sperren()) {
            return false;
        }
        try {
            if ($this->ReadAttributeString('AccessToken') !== '' && $this->ReadAttributeInteger('TokenAblauf') > time() + 600) {
                $this->RefreshPlanen();
                if ($this->GetStatus() != IS_ACTIVE) {
                    $this->SetStatus(IS_ACTIVE);
                }
                return true;
            }
            if ($this->ReadAttributeString('RefreshToken') !== '') {
                $Erneuert = $this->TokenErneuern();
                if ($Erneuert !== false) {
                    return $Erneuert === true; // null = Bring! nicht erreichbar: nicht mit Passwort neu anmelden
                }
            }
            return $this->Anmelden();
        } finally {
            $this->Freigeben();
        }
    }

    /**
     * Anmeldung mit E-Mail und Passwort. Nach einer Ablehnung wird mit wachsender Wartezeit
     * erneut versucht (Schutz vor Kontosperre); $Sofort (Button „Verbindung testen“) ignoriert sie.
     */
    private function Anmelden(bool $Sofort = false): bool
    {
        $Sperre = $this->ReadAttributeInteger('LoginSperreBis');
        if (!$Sofort && $Sperre > time()) {
            $this->SendDebug('Anmelden', 'Wartezeit nach abgelehnter Anmeldung bis ' . date('H:i', $Sperre) . ' – kein Versuch', 0);
            return false;
        }
        $this->SendDebug('Anmelden', 'mit den hinterlegten Zugangsdaten', 0);
        $Result = $this->Http('POST', EINK::API_URL . 'bringauth', [
            'email'    => $this->ReadPropertyString('EMail'),
            'password' => $this->ReadPropertyString('Passwort')
        ], 'form', false);

        if (!$Result['Success'] || !is_array($Result['Data']) || !isset($Result['Data']['access_token'])) {
            if (!self::Abgelehnt($Result['Code'])) {
                // Netz- oder Serverfehler: Tokens behalten, kein Anmeldefehler (der würde die Listen stilllegen) –
                // beim nächsten Abruf erneut. Ein Hinweis-Status aus der Einrichtung wird dabei aufgehoben.
                $this->SendDebug('Anmelden', 'Bring! nicht erreichbar (' . ($Result['Error'] !== '' ? $Result['Error'] : 'HTTP ' . $Result['Code']) . ')', 0);
                if (!in_array($this->GetStatus(), [IS_ACTIVE, EINK::STATUS_LOGIN_FEHLER], true)) {
                    $this->SetStatus(IS_ACTIVE);
                }
                return false;
            }
            $this->TokensLoeschen();
            $Fehler = $this->ReadAttributeInteger('LoginFehler') + 1;
            $Warten = (int) min(self::LOGIN_WARTEN_MAX, self::LOGIN_WARTEN_START * 2 ** min(10, $Fehler - 1));
            $this->WriteAttributeInteger('LoginFehler', $Fehler);
            $this->WriteAttributeInteger('LoginSperreBis', time() + $Warten);
            $this->SetStatus(EINK::STATUS_LOGIN_FEHLER);
            $this->LogMessage('Anmeldung bei Bring! fehlgeschlagen (HTTP ' . $Result['Code'] . ') – nächster Versuch in ' . round($Warten / 60) . ' Minuten', KL_ERROR);
            return false;
        }

        $this->LoginWartezeitBeenden();
        $D = $Result['Data'];
        $this->WriteAttributeString('Uuid', (string) ($D['uuid'] ?? ''));
        $this->WriteAttributeString('PublicUuid', (string) ($D['publicUuid'] ?? ''));
        $this->WriteAttributeString('Name', (string) ($D['name'] ?? ''));
        $this->WriteAttributeString('LoginHash', md5($this->ReadPropertyString('EMail') . '|' . $this->ReadPropertyString('Passwort')));
        $this->TokensSpeichern($D);
        return true;
    }

    /**
     * Rückgabe: true = erneuert, false = abgelehnt (neue Anmeldung nötig), null = Bring! nicht erreichbar
     */
    private function TokenErneuern(): ?bool
    {
        $this->SendDebug('TokenErneuern', '', 0);
        $Result = $this->Http('POST', EINK::API_URL . 'bringauth/token', [
            'grant_type'    => 'refresh_token',
            'refresh_token' => $this->ReadAttributeString('RefreshToken')
        ], 'form', false);

        if (!$Result['Success'] || !is_array($Result['Data']) || !isset($Result['Data']['access_token'])) {
            if (!self::Abgelehnt($Result['Code'])) {
                $this->SendDebug('TokenErneuern', 'Bring! nicht erreichbar, später erneut', 0);
                return null;
            }
            $this->SendDebug('TokenErneuern', 'fehlgeschlagen, neue Anmeldung nötig', 0);
            return false;
        }
        $this->TokensSpeichern($Result['Data']);
        return true;
    }

    /** Nur 400/401/403 am Anmelde-Endpunkt bedeuten „abgelehnt“; alles andere ist eine Netz- oder Serverstörung. */
    private static function Abgelehnt(int $Code): bool
    {
        return in_array($Code, [400, 401, 403], true);
    }

    private function LoginWartezeitBeenden(): void
    {
        if ($this->ReadAttributeInteger('LoginFehler') !== 0 || $this->ReadAttributeInteger('LoginSperreBis') !== 0) {
            $this->WriteAttributeInteger('LoginFehler', 0);
            $this->WriteAttributeInteger('LoginSperreBis', 0);
        }
    }

    private function Sperren(): bool
    {
        if (IPS_SemaphoreEnter('EINK_Token_' . $this->InstanceID, 30000)) {
            return true;
        }
        $this->SendDebug('Anmelden', 'Token-Erneuerung läuft noch in einem anderen Ablauf', 0);
        return false;
    }

    private function Freigeben(): void
    {
        IPS_SemaphoreLeave('EINK_Token_' . $this->InstanceID);
    }

    private function TokensSpeichern(array $D): void
    {
        $this->WriteAttributeString('AccessToken', (string) $D['access_token']);
        if (!empty($D['refresh_token'])) {
            $this->WriteAttributeString('RefreshToken', (string) $D['refresh_token']);
        }
        $this->WriteAttributeInteger('TokenAblauf', time() + (int) ($D['expires_in'] ?? 3600));
        $this->RefreshPlanen();
        $this->SetStatus(IS_ACTIVE);
    }

    private function TokensLoeschen(): void
    {
        $this->WriteAttributeString('AccessToken', '');
        $this->WriteAttributeString('RefreshToken', '');
        $this->WriteAttributeInteger('TokenAblauf', 0);
    }

    private function RefreshPlanen(): void
    {
        $Sekunden = $this->ReadAttributeInteger('TokenAblauf') - time() - 600;
        $Sekunden = max(60, min($Sekunden, 86400));
        $this->SetTimerInterval('TokenRefresh', $Sekunden * 1000);
    }

    /**
     * Anfrage mit automatischer Anmeldung und einem Wiederholungsversuch bei 401.
     */
    private function Anfrage(string $Method, string $Endpoint, array $Body = [], string $Type = 'none'): array
    {
        $Extern = (strpos($Endpoint, 'http') === 0);

        if (!$Extern) {
            if (!$this->Pruefen()) {
                return ['Success' => false, 'Code' => 0, 'Data' => null, 'Error' => 'Konto ist nicht aktiv'];
            }
            if ($this->GetStatus() != IS_ACTIVE || $this->ReadAttributeInteger('TokenAblauf') < time() + 60) {
                if (!$this->Verbinden()) {
                    return ['Success' => false, 'Code' => 0, 'Data' => null, 'Error' => 'Anmeldung fehlgeschlagen'];
                }
            }
        }

        $Url = $Extern ? $Endpoint : EINK::API_URL . $this->Platzhalter($Endpoint);
        $Body = $this->PlatzhalterArray($Body);

        $Benutzt = $this->ReadAttributeString('AccessToken');
        $Result = $this->Http($Method, $Url, $Body, $Type, !$Extern);
        if (!$Extern && $Result['Code'] == 401) {
            $this->SendDebug('Anfrage', '401 – melde neu an', 0);
            $Neu = false;
            if ($this->Sperren()) {
                try {
                    if ($this->ReadAttributeString('AccessToken') !== $Benutzt) {
                        $Neu = $this->ReadAttributeString('AccessToken') !== ''; // inzwischen von einem anderen Ablauf erneuert
                    } else {
                        $this->TokensLoeschen();
                        $Neu = $this->Anmelden();
                    }
                } finally {
                    $this->Freigeben();
                }
            }
            if ($Neu) {
                $Result = $this->Http($Method, $Url, $Body, $Type, true);
            }
        }
        return $Result;
    }

    private function Platzhalter(string $Text): string
    {
        return str_replace(
            ['{uuid}', '{publicUuid}'],
            [$this->ReadAttributeString('Uuid'), $this->ReadAttributeString('PublicUuid')],
            $Text
        );
    }

    private function PlatzhalterArray(array $Body): array
    {
        array_walk_recursive($Body, function (&$Wert)
        {
            if (is_string($Wert)) {
                $Wert = $this->Platzhalter($Wert);
            }
        });
        return $Body;
    }

    private function Http(string $Method, string $Url, array $Body, string $Type, bool $Auth): array
    {
        $Header = ['Accept: application/json'];
        if (strpos($Url, EINK::API_URL) === 0) {
            $Header[] = 'X-BRING-API-KEY: ' . EINK::API_KEY;
            $Header[] = 'X-BRING-CLIENT: webApp';
            $Header[] = 'X-BRING-APPLICATION: bring';
            $Header[] = 'X-BRING-COUNTRY: DE';
        }
        if ($Auth) {
            $Header[] = 'X-BRING-USER-UUID: ' . $this->ReadAttributeString('Uuid');
            $Header[] = 'Authorization: Bearer ' . $this->ReadAttributeString('AccessToken');
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $Method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

        if ($Method === 'GET' && count($Body)) {
            $Url .= (strpos($Url, '?') === false ? '?' : '&') . http_build_query($Body);
        } elseif ($Method !== 'GET') {
            if ($Type === 'json') {
                $Header[] = 'Content-Type: application/json; charset=UTF-8';
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($Body));
            } elseif ($Type === 'form') {
                $Header[] = 'Content-Type: application/x-www-form-urlencoded; charset=UTF-8';
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($Body));
            } else {
                curl_setopt($ch, CURLOPT_POSTFIELDS, '');
            }
        }
        curl_setopt($ch, CURLOPT_URL, $Url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $Header);

        $this->SendDebug('Anfrage', $Method . ' ' . $Url, 0);
        if (strpos($Url, 'bringauth') === false) {
            $this->SendDebug('Daten', json_encode($Body), 0);
        }

        $Antwort = curl_exec($ch);
        $Code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $Fehler = curl_error($ch);
        curl_close($ch);

        if ($Antwort === false) {
            $this->SendDebug('Fehler', $Fehler, 0);
            return ['Success' => false, 'Code' => 0, 'Data' => null, 'Error' => $Fehler];
        }

        $this->SendDebug('Antwort ' . $Code, strpos($Url, 'bringauth') === false ? $Antwort : '(Tokens ausgeblendet)', 0);

        $Daten = null;
        if ($Antwort !== '') {
            $Daten = json_decode($Antwort, true);
            if ($Daten === null) {
                $Daten = $Antwort;
            }
        }
        $Ok = ($Code >= 200 && $Code < 300);
        return [
            'Success' => $Ok,
            'Code'    => $Code,
            'Data'    => $Daten,
            'Error'   => $Ok ? '' : 'HTTP ' . $Code
        ];
    }
}
