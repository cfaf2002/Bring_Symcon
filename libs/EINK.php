<?php

declare(strict_types=1);

/**
 * Gemeinsame Konstanten des Moduls "Einkaufsliste".
 */
class EINK
{
    // Datenfluss
    public const DATA_TO_KONTO = '{C4FC5708-5510-46F2-850F-FEFA4791844D}';
    public const DATA_FROM_KONTO = '{8DB09141-BB64-4187-970E-7C6A4D2E79EC}';

    // Module
    public const MODUL_KONTO = '{97F6071D-028A-4BFF-B873-1DC1C67F66E4}';
    public const MODUL_KONFIGURATOR = '{ECCAFA95-80A1-44A5-900E-57C483BF0DFF}';
    public const MODUL_LISTE = '{7EDEB801-A287-42F4-B60E-88DEC71E6305}';

    // Schnittstelle (Bring!-Web-App, inoffiziell)
    public const API_URL = 'https://api.getbring.com/rest/v2/';
    public const API_KEY = 'cof4Nc6D8saplXjE3h3HXqHH8m7VU2i1Gs0g85Sp';
    public const ARTICLES_URL = 'https://web.getbring.com/locale/articles.%s.json';
    public const IMAGES_URL = 'https://web.getbring.com/assets/images/items/';

    // Benachrichtigungen
    public const NOTIFY_GOING_SHOPPING = 'GOING_SHOPPING';
    public const NOTIFY_SHOPPING_DONE = 'SHOPPING_DONE';
    public const NOTIFY_CHANGED_LIST = 'CHANGED_LIST';
    public const NOTIFY_URGENT = 'URGENT_MESSAGE';

    // Eigene Statuscodes
    public const STATUS_HINWEIS = 201;
    public const STATUS_ZUGANG_FEHLT = 202;
    public const STATUS_LOGIN_FEHLER = 203;
    public const STATUS_KEINE_VERBINDUNG = 204;
    public const STATUS_KEINE_LISTE = 205;

    /**
     * Baut die Anfrage, die eine Kind-Instanz an das Konto schickt.
     */
    public static function Request(string $Method, string $Endpoint, array $Body = [], string $Type = 'none'): string
    {
        return json_encode([
            'DataID'   => self::DATA_TO_KONTO,
            'Method'   => $Method,
            'Endpoint' => $Endpoint,
            'Body'     => $Body,
            'Type'     => $Type
        ]);
    }

    /**
     * Wertet die Antwort des Kontos aus.
     * Liefert ['Success' => bool, 'Code' => int, 'Data' => mixed, 'Error' => string]
     */
    public static function Response($Raw): array
    {
        if (!is_string($Raw) || $Raw === '') {
            return ['Success' => false, 'Code' => 0, 'Data' => null, 'Error' => 'Keine Antwort vom Konto'];
        }
        $Result = json_decode($Raw, true);
        if (!is_array($Result)) {
            return ['Success' => false, 'Code' => 0, 'Data' => null, 'Error' => 'Ungültige Antwort vom Konto'];
        }
        return $Result + ['Success' => false, 'Code' => 0, 'Data' => null, 'Error' => ''];
    }
}
