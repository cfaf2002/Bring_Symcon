<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/EINK.php';

/**
 * Bring Übersicht
 * Fasst alle Bring Listen eines Kontos in einer Kachel zusammen.
 * Antippen einer Liste öffnet sie in derselben Kachel, mit Zurück-Knopf.
 *
 * Autor: Armin Frohwerk
 */
class BringUebersicht extends IPSModuleStrict
{
    public function Create(): void
    {
        parent::Create();
        $this->RegisterAttributeString('Beobachtet', '[]');
        $this->RegisterTimer('Pruefen', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], "Pruefen", true);');
    }

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

        $this->SetVisualizationType(1);

        if (IPS_GetKernelRunlevel() != KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            return;
        }

        $this->Beobachten();
        $this->SetTimerInterval('Pruefen', 60 * 1000);
        $this->SetStatus(IS_ACTIVE);
        $this->Senden();
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        switch ($Message) {
            case IPS_KERNELSTARTED:
                $this->UnregisterMessage(0, IPS_KERNELSTARTED);
                $this->ApplyChanges();
                break;
            case VM_UPDATE:
                if (in_array($SenderID, json_decode($this->ReadAttributeString('Beobachtet'), true) ?: [])) {
                    $this->Senden();
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
            case 'Aktion':
                $A = json_decode((string) $Value, true);
                if (!is_array($A)) {
                    return;
                }
                $ID = (int) ($A['liste'] ?? 0);
                unset($A['liste']);
                if (!in_array($ID, $this->Listen(), true)) {
                    $this->Meldung('Diese Liste gibt es nicht mehr.');
                    $this->Senden();
                    return;
                }
                $Meldung = '';
                try {
                    $Meldung = (string) EINK_TileAction($ID, json_encode($A));
                } catch (Throwable $e) {
                    $Meldung = 'Aktion fehlgeschlagen: ' . $e->getMessage();
                }
                $this->Senden();
                if ($Meldung !== '') {
                    $this->Meldung($Meldung);
                }
                break;
            case 'Pruefen':
                if ($this->Beobachten()) {
                    $this->Senden();
                }
                break;
            default:
                throw new Exception('Ungültiger Ident: ' . $Ident);
        }
    }

    public function GetConfigurationForm(): string
    {
        $Form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        $Namen = [];
        foreach ($this->Listen() as $ID) {
            $Namen[] = IPS_GetName($ID);
        }
        $Form['elements'][1]['caption'] = count($Namen)
            ? 'Angezeigte Listen: ' . implode(', ', $Namen)
            : 'Es sind noch keine Bring Listen an diesem Konto angelegt.';
        return json_encode($Form);
    }

    public function GetVisualizationTile(): string
    {
        $Liste = file_get_contents(__DIR__ . '/../Liste/module.html');
        $Uebersicht = file_get_contents(__DIR__ . '/module.html');
        $Daten = json_encode($this->Daten(true), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        return $Liste . $Uebersicht . '<script>handleMessage(' . json_encode($Daten, JSON_HEX_TAG) . ');</script>';
    }

    // ------------------------------------------------------------------
    // Intern
    // ------------------------------------------------------------------

    /**
     * Alle Bring Listen am selben Konto, in der Reihenfolge des Objektbaums.
     */
    private function Listen(): array
    {
        $Parent = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        $Listen = [];
        foreach (IPS_GetInstanceListByModuleID(EINK::MODUL_LISTE) as $ID) {
            if ($Parent == 0 || IPS_GetInstance($ID)['ConnectionID'] == $Parent) {
                $Listen[] = $ID;
            }
        }
        usort($Listen, function (int $a, int $b): int
        {
            $Pa = IPS_GetObject($a)['ObjectPosition'];
            $Pb = IPS_GetObject($b)['ObjectPosition'];
            return $Pa <=> $Pb ?: strcasecmp(IPS_GetName($a), IPS_GetName($b));
        });
        return $Listen;
    }

    /**
     * Beobachtet die Variable "Letzte Aktualisierung" jeder Liste.
     * Liefert true, wenn sich die Menge der Listen geändert hat.
     */
    private function Beobachten(): bool
    {
        $Alt = json_decode($this->ReadAttributeString('Beobachtet'), true) ?: [];
        $Neu = [];
        foreach ($this->Listen() as $ID) {
            $VarID = @IPS_GetObjectIDByIdent('Zeitpunkt', $ID);
            if ($VarID !== false && $VarID > 0) {
                $Neu[] = $VarID;
            }
        }
        foreach (array_diff($Alt, $Neu) as $VarID) {
            if (IPS_VariableExists($VarID)) {
                $this->UnregisterMessage($VarID, VM_UPDATE);
            }
        }
        foreach ($Neu as $VarID) {
            $this->RegisterMessage($VarID, VM_UPDATE);
        }
        sort($Alt);
        sort($Neu);
        $this->WriteAttributeString('Beobachtet', json_encode($Neu));
        return $Alt !== $Neu;
    }

    private function Daten(bool $MitKatalog): array
    {
        $Daten = ['listen' => []];
        foreach ($this->Listen() as $ID) {
            try {
                $Liste = json_decode((string) EINK_GetTileData($ID, $MitKatalog && !isset($Daten['katalog'])), true);
            } catch (Throwable $e) {
                $this->SendDebug('Liste ' . $ID, $e->getMessage(), 0);
                continue;
            }
            if (!is_array($Liste)) {
                continue;
            }
            if (isset($Liste['katalog'])) {
                $Daten['katalog'] = $Liste['katalog'];
                unset($Liste['katalog']);
            }
            $Liste['id'] = $ID;
            $Daten['listen'][] = $Liste;
        }
        return $Daten;
    }

    private function Senden(): void
    {
        $this->UpdateVisualizationValue(json_encode($this->Daten(false)));
    }

    private function Meldung(string $Text): void
    {
        $this->UpdateVisualizationValue(json_encode(['meldung' => $Text]));
    }
}
