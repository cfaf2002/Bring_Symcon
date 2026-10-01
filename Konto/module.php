<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/EINK.php';

/**
 * Einkaufsliste Konto
 * Meldet sich am Bring!-Konto an, verwaltet die Tokens und leitet
 * alle Anfragen der Listen- und Konfigurator-Instanzen an die Cloud weiter.
 *
 * Autor: Armin Frohwerk
 */
class EinkaufslisteKonto extends IPSModule
{
    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyBoolean('HinweisBestaetigt', false);
        $this->RegisterPropertyBoolean('Aktiv', true);
        $this->RegisterPropertyString('EMail', '');
        $this->RegisterPropertyString('Passwort', '');

        $this->RegisterAttributeString('Uuid', '');
        $this->RegisterAttributeString('PublicUuid', '');
        $this->RegisterAttributeString('Name', '');
        $this->RegisterAttributeString('AccessToken', '');
        $this->RegisterAttributeString('RefreshToken', '');
        $this->RegisterAttributeInteger('TokenAblauf', 0);
        $this->RegisterAttributeString('LoginHash', '');

        $this->RegisterTimer('TokenRefresh', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], "TokenRefresh", true);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->SetTimerInterval('TokenRefresh', 0);

        if (IPS_GetKernelRunlevel() != KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            return;
        }

        if (!$this->Pruefen()) {
            return;
        }

        // Zugangsdaten geändert? Dann alte Tokens verwerfen.
        $Hash = md5($this->ReadPropertyString('EMail') . '|' . $this->ReadPropertyString('Passwort'));
        if ($Hash !== $this->ReadAttributeString('LoginHash')) {
            $this->TokensLoeschen();
        }

        $this->Verbinden();
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
        $this->TokensLoeschen();
        $Ok = $this->Anmelden();
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
     */
    private function Verbinden(): bool
    {
        if ($this->ReadAttributeString('AccessToken') !== '' && $this->ReadAttributeInteger('TokenAblauf') > time() + 600) {
            $this->RefreshPlanen();
            if ($this->GetStatus() != IS_ACTIVE) {
                $this->SetStatus(IS_ACTIVE);
            }
            return true;
        }
        if ($this->ReadAttributeString('RefreshToken') !== '' && $this->TokenErneuern()) {
            return true;
        }
        return $this->Anmelden();
    }

    private function Anmelden(): bool
    {
        $this->SendDebug('Anmelden', $this->ReadPropertyString('EMail'), 0);
        $Result = $this->Http('POST', EINK::API_URL . 'bringauth', [
            'email'    => $this->ReadPropertyString('EMail'),
            'password' => $this->ReadPropertyString('Passwort')
        ], 'form', false);

        if (!$Result['Success'] || !is_array($Result['Data']) || !isset($Result['Data']['access_token'])) {
            $this->TokensLoeschen();
            $this->SetStatus(EINK::STATUS_LOGIN_FEHLER);
            $this->LogMessage('Anmeldung bei Bring! fehlgeschlagen (HTTP ' . $Result['Code'] . ')', KL_ERROR);
            return false;
        }

        $D = $Result['Data'];
        $this->WriteAttributeString('Uuid', (string) ($D['uuid'] ?? ''));
        $this->WriteAttributeString('PublicUuid', (string) ($D['publicUuid'] ?? ''));
        $this->WriteAttributeString('Name', (string) ($D['name'] ?? ''));
        $this->WriteAttributeString('LoginHash', md5($this->ReadPropertyString('EMail') . '|' . $this->ReadPropertyString('Passwort')));
        $this->TokensSpeichern($D);
        return true;
    }

    private function TokenErneuern(): bool
    {
        $this->SendDebug('TokenErneuern', '', 0);
        $Result = $this->Http('POST', EINK::API_URL . 'bringauth/token', [
            'grant_type'    => 'refresh_token',
            'refresh_token' => $this->ReadAttributeString('RefreshToken')
        ], 'form', false);

        if (!$Result['Success'] || !is_array($Result['Data']) || !isset($Result['Data']['access_token'])) {
            $this->SendDebug('TokenErneuern', 'fehlgeschlagen, neue Anmeldung nötig', 0);
            return false;
        }
        $this->TokensSpeichern($Result['Data']);
        return true;
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

        $Result = $this->Http($Method, $Url, $Body, $Type, !$Extern);
        if (!$Extern && $Result['Code'] == 401) {
            $this->SendDebug('Anfrage', '401 – melde neu an', 0);
            $this->TokensLoeschen();
            if ($this->Anmelden()) {
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
