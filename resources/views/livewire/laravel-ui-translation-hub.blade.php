<?php

use Livewire\Volt\Component;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Livewire\Attributes\On;

new class extends Component {
    public $baseLocaleIso;
    public $targetLocaleIso;
    public $baseLanguageData = [];
    public $targetLanguageData = [];
    public $availableLocales = [];
    public $filter = 'all';

    public $languagesTable;

    // Neue Properties für das Formular
    public $showAddForm = false;
    public $newLanguageName = '';
    public $newLanguageIso = '';
    public $errorMessage = '';

    // NEU: Autotranslate SaaS Properties
    public $autoTranslateEnabled = false;
    public $showApiKeyModal = false;
    public $apiKeyInput = '';
    public $wordBalance = '---'; // Restkontingent vom Server
    public $apiErrorMessage = '';

    public function mount()
    {
        $this->languagesTable = config('ui-translation-hub.table_name', 'l7languages');
        $this->baseLocaleIso = config('app.fallback_locale');
        $this->baseLanguageData = $this->loadIso($this->baseLocaleIso);

        //$this->availableLocales = DB::table($this->languagesTable)->where('code', '!=', $this->baseLocaleIso)->pluck('code')->toArray();
        // Optimierter code f. code->name
        // [
        //     "de" => "Deutsch",
        //     "es" => "Español"
        // ]
        $this->availableLocales = DB::table($this->languagesTable)
            ->where('code', '!=', $this->baseLocaleIso)
            ->pluck('name', 'code') // <--- Zuerst die Value-Spalte, dann die Key-Spalte!
            ->toArray();

        // Setze das initiale Target auf die erste verfügbare Sprache (falls vorhanden)
        $this->targetLocaleIso = !empty($this->availableLocales) ? array_key_first($this->availableLocales) : null;
        $this->targetLanguageData = $this->targetLocaleIso ? $this->loadIso($this->targetLocaleIso) : [];

        // NEU: Prüfen, ob bereits ein Key in der Config (bzw. .env) existiert
        $savedKey = config('ui-translation-hub.api_key');
        if (!empty($savedKey)) {
            $this->apiKeyInput = $savedKey;
            $this->autoTranslateEnabled = true;
            $this->checkApiBalance(); // Kontingent direkt abfragen
        }
    }
    /*---------------------------------------------------------------------------------------------------------*/
    public function checkApiBalance()
    {
        if (empty($this->apiKeyInput)) {
            return;
        }

        // HINWEIS: Hier kommt später dein echter HTTP-Request zu deinem API-Server hin!
        // $response = Http::withToken($this->apiKeyInput)->get('https://api.l7studios.de/v1/balance');

        // Temporärer Mock für das UI-Testing:
        $this->wordBalance = '124.500';
    }
    /*---------------------------------------------------------------------------------------------------------*/
    public function verifyAndEnableApi()
    {
        $this->apiErrorMessage = '';

        if (empty($this->apiKeyInput)) {
            $this->apiErrorMessage = 'Bitte gib einen gültigen API-Key ein.';
            return;
        }

        // TODO: Hier echten Handshake mit deinem API-Server machen!
        // $response = Http::post('https://api.l7studios.de/v1/verify', ['api_key' => $this->apiKeyInput]);
        // if ($response->failed()) { $this->apiErrorMessage = 'Ungültiger API-Key.'; return; }

        // Wenn erfolgreich: In der Session oder Cache temporär merken,
        // damit es bis zum permanenten .env-Eintrag aktiv bleibt
        session(['UI_TRANSLATION_HUB_API_KEY' => $this->apiKeyInput]);

        $this->autoTranslateEnabled = true;
        $this->showApiKeyModal = false;
        $this->checkApiBalance();
    }
    /*---------------------------------------------------------------------------------------------------------*/
    public function disableApi()
    {
        session()->forget('UI_TRANSLATION_HUB_API_KEY');
        $this->autoTranslateEnabled = false;
        $this->wordBalance = '---';
        $this->apiKeyInput = '';
    }
    /*---------------------------------------------------------------------------------------------------------*/
    public function loadAvailableLocales()
    {
        $this->availableLocales = DB::table($this->languagesTable)->where('code', '!=', $this->baseLocaleIso)->pluck('name', 'code')->toArray();
    }
    /*---------------------------------------------------------------------------------------------------------*/
    public function loadIso($iso)
    {
        $path = lang_path("{$iso}.json");
        return File::exists($path) ? json_decode(File::get($path), true) : [];
    }
    /*---------------------------------------------------------------------------------------------------------*/
    #[On('save-key')]
    public function handleSave($key, $val)
    {
        $this->saveKey($key, $val);
    }
    /*---------------------------------------------------------------------------------------------------------*/
    public function saveKey($key, $newText)
    {
        $this->targetLanguageData[$key] = bin2hex($newText);
        $this->persistData();
    }
    /*---------------------------------------------------------------------------------------------------------*/
    private function persistData()
    {
        $path = lang_path($this->targetLocaleIso . '.json');
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        File::put($path, json_encode($this->targetLanguageData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        Cache::forget("lang_json_{$this->targetLocaleIso}");
    }
    /*---------------------------------------------------------------------------------------------------------*/
    public function cheat($hexKey)
    {
        $text = hex2bin($this->baseLanguageData[$hexKey]);
        $url = 'https://translate.googleapis.com/translate_a/single?client=gtx&dt=t&sl=' . $this->baseLocaleIso . '&tl=' . $this->targetLocaleIso . '&q=' . urlencode($text);

        $response = @file_get_contents($url);
        if ($response) {
            $json = json_decode($response, true);

            // NEU: Wir iterieren durch alle übersetzten Segmente, nicht nur das erste
            if (isset($json[0])) {
                $translatedText = '';
                foreach ($json[0] as $segment) {
                    $translatedText .= $segment[0];
                }

                // Jetzt haben wir den kompletten Text wieder zusammengefügt
                $this->targetLanguageData[$hexKey] = bin2hex($translatedText);
                $this->persistData();
            }
        }
    }
    /*---------------------------------------------------------------------------------------------------------*/
    public function updatedTargetLocaleIso()
    {
        if ($this->targetLocaleIso === 'ADD_NEW_LOCALE') {
            $this->showAddForm = true;

            // Setzt das Dropdown direkt auf die Base/Fallback-Sprache zurück
            $this->targetLocaleIso = $this->baseLocaleIso;

            return;
        }

        $this->targetLanguageData = $this->loadIso($this->targetLocaleIso);
    }
    /*---------------------------------------------------------------------------------------------------------*/
    public function translateAll()
    {
        foreach ($this->baseLanguageData as $hexKey => $value) {
            // Wir übersetzen nur, wenn es noch gar keinen Eintrag gibt ODER der Eintrag leer ist
            if (!isset($this->targetLanguageData[$hexKey]) || empty(hex2bin($this->targetLanguageData[$hexKey]))) {
                $this->cheat($hexKey, false); // false verhindert das sofortige Speichern bei jedem einzelnen Wort
            }
        }
        // Erst wenn alle durch sind, einmalig speichern
        $this->persistData();
    }
    /*---------------------------------------------------------------------------------------------------------*/
    public function syncLanguageFilesWithDatabase()
    {
        // GEFIXT: Holt die Codes direkt über die DB-Facade mit deiner Tabellen-Variable
        $activeLocales = DB::table($this->languagesTable)->pluck('code')->toArray();

        $langPath = lang_path();
        if (!File::exists($langPath)) {
            return;
        }

        // 2. Alle JSON-Dateien im Ordner scannen
        $files = File::files($langPath);

        foreach ($files as $file) {
            if ($file->getExtension() === 'json') {
                $locale = $file->getFilenameWithoutExtension(); // z.B. 'es'

                // 3. Wenn die Datei existiert, aber NICHT mehr in der DB ist -> Löschen!
                if (!in_array($locale, $activeLocales)) {
                    File::delete($file->getPathname());
                }
            }
        }
    }
    /*---------------------------------------------------------------------------------------------------------*/
    public function addNewLanguage()
    {
        $this->errorMessage = '';
        $iso = strtolower(trim($this->newLanguageIso));
        $name = trim($this->newLanguageName);

        if (empty($iso) || empty($name)) {
            $this->errorMessage = 'Bitte alle Felder ausfüllen.';
            return;
        }

        // Überprüfen, ob das ISO bereits existiert (inklusive Base-Locale)
        if ($iso === $this->baseLocaleIso) {
            $this->errorMessage = 'ISO entspricht der Base-Sprache.';
            return;
        }

        $exists = DB::table($this->languagesTable)->where('code', $iso)->exists();

        if ($exists) {
            $this->errorMessage = 'ISO existiert bereits.';
            return;
        }

        // Einfügen in die DB (Spaltennamen 'name' und 'code' passend zur Tabellenstruktur)
        DB::table($this->languagesTable)->insert([
            'name' => $name,
            'code' => $iso,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Properties zurücksetzen & Listen aktualisieren
        $this->newLanguageName = '';
        $this->newLanguageIso = '';
        $this->showAddForm = false;

        $this->loadAvailableLocales();
        $this->targetLocaleIso = $iso;
        $this->targetLanguageData = $this->loadIso($iso);
    }
    /*---------------------------------------------------------------------------------------------------------*/
    public function deleteIndex($hexKey)
    {
        $langPath = lang_path();
        if (!File::exists($langPath)) {
            return;
        }

        $files = File::files($langPath);
        foreach ($files as $file) {
            if ($file->getExtension() === 'json') {
                $locale = $file->getFilenameWithoutExtension();
                $path = $file->getPathname();

                if (File::exists($path)) {
                    $data = json_decode(File::get($path), true) ?? [];
                    if (isset($data[$hexKey])) {
                        unset($data[$hexKey]);
                        File::put($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                        Cache::forget("lang_json_{$locale}");
                    }
                }
            }
        }

        // Setzt die Arrays für das Interface direkt zurück, damit die Zeile sofort verschwindet
        $this->baseLanguageData = $this->loadIso($this->baseLocaleIso);
        $this->targetLanguageData = $this->targetLocaleIso ? $this->loadIso($this->targetLocaleIso) : [];
    }
    /*---------------------------------------------------------------------------------------------------------*/
};
?>
{{-- 
   --color-night-void: #0c0b16;
   --color-night-canvas: #161426;
   --color-night-casts: #080e20;
   --color-night-flare: #1c1a2e;
   --color-night-overlay: rgba(3, 14, 47, 0.729);
   --color-night-text: #9da4af;
   --color-night-border-soft: rgba(255, 255, 255, 0.08);
   --color-night-border-hard: #374151;
   /* #endregion base-colors */

   /* #region daymode colors  */
   --color-day-void: #f0f2f5; /* Subtiler Hintergrund (Wealthfront-Style) */
   --color-day-canvas: #ffffff; /* Reinweißes Papier */
   --color-day-casts: #f7f9fb; /* Sehr helles Grau für Sidebar/Header */
   --color-day-flare: #ffffff; /* Weiß mit starkem Schatten */
   --color-day-overlay: rgba(15, 23, 42, 0.4); /* Sanftes Abdunkeln */
   --color-day-text: #192a0f; /* Slate-900 für perfekte Lesbarkeit */
   --color-day-border-soft: #e2e8f0; /* Subtile Linien */
   --color-day-border-hard: #cbd5e1; /* Definierte Kanten */ --}}

<div class="p-4 border rounded-sm border-[#cbd5e1] dark:border-[#374151] bg-[#ffffff] dark:bg-[#161426]">
    {{-- Tilte-bar --}}
    <div class="flex justify-between items-center mb-1 p-3 ">
        <div class="mx-0 font-black italic text-2xl tracking-tighter text-gray-800 dark:text-white uppercase mb-2">
            Translation <span class="text-indigo-600 dark:text-indigo-400">Hub</span>
        </div>
        {{-- Source Language Badge --}}
        <div class="float-left  px-2 py-2 text-[10px]   text-gray-600 dark:text-gray-300 ">
            {{ __t('Detected Source') }}: <span
                class="text-[12px] text-bold text-indigo-600 dark:text-indigo-400">{{ strtoupper($baseLocaleIso) }}</span>
        </div>
    </div>

    {{-- Action-bar --}}
    <div
        class="flex justify-between items-center mb-8 p-3 bg-[#f7f9fb] dark:bg-[#080e20] rounded-sm border 
        border-[#cbd5e1] dark:border-[#374151]">

        {{-- Linke Seite: Aktionen & Info --}}
        <div class="flex items-center gap-4">


            {{-- Translate All Button --}}
            <button type="button" wire:click="translateAll" wire:loading.attr="disabled"
                @if (empty($availableLocales)) disabled @endif
                class="text-[10px] font-bold px-2 py-3 rounded-sm uppercase bg-indigo-600 hover:bg-indigo-700 text-white transition-colors disabled:opacity-50 disabled:cursor-not-allowed">
                <span wire:loading.remove wire:target="translateAll">Translate All Missing</span>
                <span wire:loading wire:target="translateAll">Translating...</span>
            </button>

            {{-- Filter Options --}}
            <select wire:model.live="filter"
                class="cursor-pointer text-[10px] font-bold px-2 py-2 rounded-sm uppercase border-gray-200 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200">
                <option value="all">Show All</option>
                <option value="missing">Missing Only</option>
            </select>
        </div>

        {{-- Rechte Seite: Target Language Auswahl --}}
        <div class="flex items-center gap-2">
            <span class="text-[10px] font-bold uppercase text-gray-400">Target:</span>

            <select wire:model.live="targetLocaleIso"
                class="cursor-pointer text-[10px] font-bold px-2 py-2 rounded-sm uppercase 
                border-gray-200 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200">

                @if (empty($availableLocales))
                    <option value="">{{ __t('no Languages') }}</option>
                @else
                    @foreach ($availableLocales as $code => $name)
                        <option value="{{ $code }}">
                            {{ strtoupper($code) }} - {{ __t($name) }}
                        </option>
                    @endforeach
                @endif

                <optgroup label="──────────────────">
                    <option class="dark:text-gray-800 font-bold text-indigo-600 dark:text-indigo-400"
                        value="ADD_NEW_LOCALE">+ {{ __t('Add new Language') }}</option>
                </optgroup>
            </select>
        </div>
    </div>
    {{-- actionbar-end --}}

    {{-- actionbar2-start --}}
    <div
        class="flex justify-between items-center mb-8 p-3 rounded-sm border transition-colors duration-200
        {{ $autoTranslateEnabled
            ? 'bg-indigo-50/40 dark:bg-indigo-950/10 border-indigo-200 dark:border-indigo-900/50'
            : 'bg-gray-100 dark:bg-gray-900/50 border-gray-200 dark:border-gray-800 opacity-75' }}">

        {{-- Linke Seite: Checkbox / Toggle Status --}}
        <div class="flex items-center gap-3">
            <label class="relative inline-flex items-center cursor-pointer select-none">
                <input type="checkbox"
                    wire:click="{{ $autoTranslateEnabled ? 'disableApi' : '$set(\'showApiKeyModal\', true)' }}"
                    @checked($autoTranslateEnabled) class="sr-only peer">
                <div
                    class="w-8 h-4 bg-gray-300 dark:bg-gray-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-3 after:w-3 after:transition-all peer-checked:bg-indigo-600">
                </div>
                <span class="ml-2 text-[10px] font-bold uppercase tracking-wider text-gray-700 dark:text-gray-300">
                    {{ $autoTranslateEnabled ? 'Autotranslate Active' : 'Enable Autotranslate' }}
                </span>
            </label>
        </div>

        {{-- Rechte Seite: Gated Actions (Nur aktiv/sichtbar wenn enabled) --}}
        <div class="flex items-center gap-4">
            @if ($autoTranslateEnabled)
                <div class="text-[10px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                    Remaining Words: <span
                        class="text-indigo-600 dark:text-indigo-400 font-black">{{ $wordBalance }}</span>
                </div>

                <button type="button" wire:click="translateAll" wire:loading.attr="disabled"
                    @if (empty($availableLocales)) disabled @endif
                    class="text-[10px] font-bold px-3 py-1.5 rounded-sm uppercase bg-indigo-600 hover:bg-indigo-700 text-white transition-colors disabled:opacity-50">
                    <span wire:loading.remove wire:target="translateAll">Translate All Missing</span>
                    <span wire:loading wire:target="translateAll">Translating...</span>
                </button>
            @else
                <span class="text-[10px] font-medium italic text-gray-400 dark:text-gray-500">
                    Connect your L7 Translation Hub Account to unlock Cloud Features.
                </span>
            @endif
        </div>
    </div>
    {{-- actionbar-end --}}


    {{-- add Language form Modal --}}
    @if ($showAddForm)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-xs">
            <div
                class="bg-white dark:bg-[#161426] border border-gray-200 dark:border-gray-800 rounded-sm w-full max-w-md p-6 shadow-xl animate-fade-in">

                <div class="text-sm font-black uppercase tracking-wider text-gray-800 dark:text-white mb-4">
                    {{ __t('Add new Language') }}
                </div>

                <div class="flex flex-col gap-3 mb-4">
                    <div>
                        <label class="text-[9px] font-bold uppercase text-gray-400 block mb-1">Sprachname</label>
                        <input type="text" wire:model="newLanguageName" placeholder="z.B. Spanish"
                            class="text-xs px-3 py-2 rounded-sm border border-gray-300 dark:border-gray-700 bg-gray-50 dark:bg-gray-900 text-gray-800 dark:text-white focus:outline-none focus:ring-1 focus:ring-indigo-500 w-full" />
                    </div>

                    <div>
                        <label class="text-[9px] font-bold uppercase text-gray-400 block mb-1">ISO-Code</label>
                        <input type="text" wire:model="newLanguageIso" placeholder="z.B. es" maxlength="5"
                            class="text-xs px-3 py-2 rounded-sm border border-gray-300 dark:border-gray-700 bg-gray-50 dark:bg-gray-900 text-gray-800 dark:text-white focus:outline-none focus:ring-1 focus:ring-indigo-500 w-full uppercase" />
                    </div>

                    @if ($errorMessage)
                        <div class="text-[10px] text-red-500 font-bold uppercase mt-1">
                            ⚠️ {{ $errorMessage }}
                        </div>
                    @endif
                </div>

                <div class="flex justify-end gap-2 pt-2 border-t border-gray-100 dark:border-gray-800">
                    <button type="button" wire:click="$set('showAddForm', false)"
                        class="text-[10px] font-bold px-3 py-2 rounded-sm uppercase bg-gray-400 hover:bg-gray-500 text-white transition-colors">
                        Abbrechen
                    </button>
                    <button type="button" wire:click="addNewLanguage"
                        class="text-[10px] font-bold px-4 py-2 rounded-sm uppercase bg-emerald-600 hover:bg-emerald-700 text-white transition-colors shadow-sm">
                        Speichern
                    </button>
                </div>
            </div>
        </div>
    @endif
    {{-- end  add Language form --}}

    {{-- Translation table --}}
    <div>
        <div class="overflow-x-auto  rounded-sm border border-gray-300 dark:border-gray-800 shadow-sm">
            <table class="w-full text-left border-collapse table-fixed">
                <thead>
                    <tr
                        class="bg-[#f7f9fb] dark:bg-[#1c1a2e] border-b border-gray-300 
                        dark:border-gray-800 text-[10px] font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                        <th class="p-3 w-[45px] text-center"></th>
                        <th class="p-3 w-4/12">{{ __t('Source Key / Text') }}</th>
                        <th class="p-3 w-8/12">{{ __t('Target Translation') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-gray-800">
                    @foreach ($baseLanguageData as $key => $value)
                        @if ($filter == 'all' || !isset($targetLanguageData[$key]))
                            <tr wire:key="row-{{ $key }}"
                                class="hover:bg-gray-50/40 dark:hover:bg-gray-900/20">
                                {{-- Delete action cell --}}
                                <td class="p-3 text-center align-top">
                                    <button type="button" wire:click="deleteIndex('{{ $key }}')"
                                        wire:confirm="{{ __t('Do you really want to remove this key from all languages ?') }}"
                                        class="border border-gray-200 dark:border-gray-600 p-1 
                                        rounded-sm text-sm cursor-pointer hover:bg-red-50 
                                        dark:hover:bg-red-950/30 hover:border-red-300 dark:hover:border-red-900">❌</button>
                                </td>
                                {{-- Source Text Cell --}}
                                <td
                                    class="p-3 text-[11px] font-bold text-gray-500 align-top break-words leading-relaxed select-all">
                                    {{ hex2bin($key) }}
                                </td>
                                {{-- Input Textarea & Tools Cell --}}
                                <td class="p-3 align-top">
                                    <div class="flex gap-2 items-start w-full">
                                        <textarea id="textarea-{{ $key }}" wire:blur="saveKey('{{ $key }}', $event.target.value)"
                                            rows="2"
                                            class="ui-textarea w-full text-[12px] p-2 border border-gray-300 dark:border-gray-700 rounded-sm bg-gray-50 dark:bg-gray-900 text-gray-800 dark:text-gray-100 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 resize-y min-h-[42px]">{{ hex2bin($targetLanguageData[$key] ?? '') }}</textarea>
                                        {{-- Translate & Save Buttons --}}
                                        <div class="flex flex-col gap-1.5 shrink-0">
                                            @if ($autoTranslateEnabled)
                                                <button type="button" wire:click="cheat('{{ $key }}')"
                                                    class="flex items-center justify-center border border-gray-200 dark:border-gray-600 rounded-sm p-1.5 bg-white dark:bg-gray-800 text-base cursor-pointer hover:text-indigo-500 hover:border-indigo-400 dark:hover:border-indigo-500 w-8 h-8"
                                                    title="Auto-Translate (Google)">🌐
                                                </button>
                                            @endif
                                            <button type="button"
                                                onclick="Livewire.dispatch('save-key', { key: '{{ $key }}', val: document.getElementById('textarea-{{ $key }}').value })"
                                                class="flex items-center justify-center border border-gray-200 dark:border-gray-600 rounded-sm p-1.5 bg-white dark:bg-gray-800 text-base cursor-pointer hover:text-emerald-500 hover:border-emerald-400 dark:hover:border-emerald-500 w-8 h-8"
                                                title="Save Translation">✅</button>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- NEU: API Key Modal --}}
    @if ($showApiKeyModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-xs">
            <div
                class="bg-white dark:bg-[#161426] border border-gray-200 dark:border-gray-800 rounded-sm w-full max-w-md p-6 shadow-xl animate-fade-in">
                <div class="text-sm font-black uppercase tracking-wider text-gray-800 dark:text-white mb-2">
                    L7 Translation Hub Cloud-Setup
                </div>
                <p class="text-[11px] text-gray-500 dark:text-gray-400 mb-4 leading-relaxed">
                    Erstelle ein Projekt im Dashboard auf <a href="https://hub.l7studios.de" target="_blank"
                        class="text-indigo-500 underline font-bold">hub.l7studios.de</a>, um deinen API-Key zu
                    erhalten.
                    Das automatische Kontingent spart dir wertvolle Zeit beim Übersetzen.
                </p>

                <div class="flex flex-col gap-1 mb-4">
                    <label class="text-[9px] font-bold uppercase text-gray-400">Dein API-Key</label>
                    <input type="password" wire:model="apiKeyInput" placeholder="l7_live_..."
                        class="text-xs px-3 py-2 rounded-sm border border-gray-300 dark:border-gray-700 bg-gray-50 dark:bg-gray-900 text-gray-800 dark:text-white focus:outline-none focus:ring-1 focus:ring-indigo-500 w-full" />

                    @if ($apiErrorMessage)
                        <span class="text-[10px] text-red-500 font-bold uppercase mt-1">⚠️
                            {{ $apiErrorMessage }}</span>
                    @endif
                </div>

                <div class="flex justify-end gap-2 pt-2 border-t border-gray-100 dark:border-gray-800">
                    <button type="button" wire:click="$set('showApiKeyModal', false)"
                        class="text-[10px] font-bold px-3 py-2 rounded-sm uppercase bg-gray-400 hover:bg-gray-500 text-white transition-colors">
                        Abbrechen
                    </button>
                    <button type="button" wire:click="verifyAndEnableApi"
                        class="text-[10px] font-bold px-4 py-2 rounded-sm uppercase bg-indigo-600 hover:bg-indigo-700 text-white transition-colors shadow-sm">
                        Verifizieren & Aktivieren
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
