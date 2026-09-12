<?php

return [
    /*
     * Der Name der Datenbank-Tabelle, in der die
     * aktiven Sprachen verwaltet werden.
     */
    'table_name' => 'l7languages',
    /*
    |--------------------------------------------------------------------------
    | API Server URL
    |--------------------------------------------------------------------------
    | Die Basis-URL deines L7 Translation Hub API-Servers.
    | Lokal z.B. http://l7-translation-api-server.test oder ngrok URL.
    |
    */
    'api_url' => env('UI_TRANSLATION_HUB_API_URL', 'http://l7-translation-api-server.test'),
];