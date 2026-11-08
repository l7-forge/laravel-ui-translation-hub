<?php

if (! function_exists('canonicalize_translation_key')) {
    /**
     * Wandelt Laravel-Platzhalter (:name, {name}) sequentiell in __VAR1__, __VAR2__ um.
     * Diese Funktion wird im Collector / Importer beim Erfassen neuer Keys genutzt.
     *
     * @param string $key
     * @return string
     */
    function canonicalize_translation_key(string $key): string
    {
        $index = 0;

        return preg_replace_callback('/:([a-zA-Z0-9_]+)|{([a-zA-Z0-9_]+)}/', function () use (&$index) {
            $index++;
            return '__VAR' . $index . '__';
        }, $key);
    }
}

if (! function_exists('__t')) {
    /**
     * Übersetzt den übergebenen String anhand des kanonischen Hex-Index
     * und mappt übergebene Array-Werte positional auf __VAR1__, __VAR2__ usw.
     *
     * @param string|null $key
     * @param array $replace
     * @param string|null $locale
     * @return string|mixed
     */
    function __t($key = null, array $replace = [], $locale = null)
    {
        if (is_null($key)) {
            return app('custom_translator');
        }

        // 1. Hex-Key des (bereits im Collector kanonisierten) Quelltexts generieren
        $hexKey = bin2hex($key);

        // 2. Übersetzung holen
        $translation = app('custom_translator')->translate($hexKey, $locale);

        // Fallback auf den Quelltext, wenn noch keine Übersetzung existiert
        if (is_null($translation)) {
            $translation = $key;
        }

        // 3. Wenn keine Platzhalter übergeben wurden, direkt zurückgeben
        if (empty($replace)) {
            return $translation;
        }

        // 4. Zero-Regex Positional Replacement zur Laufzeit:
        // mappt index 0 auf __VAR1__, index 1 auf __VAR2__ etc.
        $replaceData = [];
        $values = array_values($replace);

        foreach ($values as $index => $value) {
            $varNumber = $index + 1;
            $replaceData['__VAR' . $varNumber . '__'] = $value;
        }

        return strtr($translation, $replaceData);
    }
}