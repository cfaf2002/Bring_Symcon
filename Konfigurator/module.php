<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/EINK.php';

/**
 * Bring Konfigurator
 * Zeigt alle Listen des Bring!-Kontos und legt sie als Instanzen an.
 *
 * Autor: Armin Frohwerk
 */
class BringKonfigurator extends IPSModuleStrict
{
    public function Create(): void
    {
        parent::Create();
    }

    /**
     * Übergeordnete Instanz: Bring Konto
     */
    public function GetCompatibleParents(): string
    {
        return json_encode([
            'type'      => 'require',
            'moduleIDs' => [EINK::MODUL_KONTO]
        ]);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
    }

    public function ReceiveData(string $JSONString): string
    {
        return '';
    }

    public function GetConfigurationForm(): string
    {
        $Form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        if (!$this->HasActiveParent()) {
            $Form['actions'][0]['caption'] = 'Das Konto ist nicht verbunden. Bitte zuerst die Konto-Instanz einrichten.';
            $Form['actions'][0]['visible'] = true;
            return json_encode($Form);
        }

        $Result = EINK::Response(@$this->SendDataToParent(EINK::Request('GET', 'bringusers/{uuid}/lists')));
        $Listen = ($Result['Success'] && is_array($Result['Data'])) ? ($Result['Data']['lists'] ?? []) : [];
        if (!$Result['Success']) {
            $Form['actions'][0]['caption'] = 'Listen konnten nicht geladen werden: ' . $Result['Error'];
            $Form['actions'][0]['visible'] = true;
        }

        // Vorhandene Listen-Instanzen am selben Konto
        $Parent = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        $Vorhanden = [];
        foreach (IPS_GetInstanceListByModuleID(EINK::MODUL_LISTE) as $ID) {
            if (IPS_GetInstance($ID)['ConnectionID'] == $Parent) {
                $Vorhanden[IPS_GetProperty($ID, 'ListUuid')] = $ID;
            }
        }

        $Werte = [];
        foreach ($Listen as $Liste) {
            $Uuid = (string) ($Liste['listUuid'] ?? '');
            if ($Uuid === '') {
                continue;
            }
            $Name = (string) ($Liste['name'] ?? $Uuid);
            $Werte[] = [
                'name'       => $Name,
                'listUuid'   => $Uuid,
                'instanceID' => $Vorhanden[$Uuid] ?? 0,
                'create'     => [
                    'moduleID'      => EINK::MODUL_LISTE,
                    'name'          => $Name,
                    'configuration' => [
                        'ListUuid' => $Uuid,
                        'ListName' => $Name
                    ]
                ]
            ];
            unset($Vorhanden[$Uuid]);
        }
        // Instanzen, deren Liste es nicht mehr gibt
        foreach ($Vorhanden as $Uuid => $ID) {
            $Werte[] = [
                'name'       => IPS_GetName($ID),
                'listUuid'   => (string) $Uuid,
                'instanceID' => $ID
            ];
        }

        // Übersicht über alle Listen in einer Kachel
        $Uebersicht = 0;
        foreach (IPS_GetInstanceListByModuleID(EINK::MODUL_UEBERSICHT) as $ID) {
            if (IPS_GetInstance($ID)['ConnectionID'] == $Parent) {
                $Uebersicht = $ID;
                break;
            }
        }
        array_unshift($Werte, [
            'name'       => 'Übersicht (alle Listen in einer Kachel)',
            'listUuid'   => '–',
            'instanceID' => $Uebersicht,
            'create'     => [
                'moduleID'      => EINK::MODUL_UEBERSICHT,
                'name'          => 'Einkaufslisten',
                'configuration' => new stdClass()
            ]
        ]);

        $Form['actions'][1]['values'] = $Werte;
        return json_encode($Form);
    }
}
