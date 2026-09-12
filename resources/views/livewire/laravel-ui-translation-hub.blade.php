<?php

use Livewire\Volt\Component;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;

new class extends Component {
    public $baseLocaleIso;
    public $targetLocaleIso;
    public $baseLanguageData = [];
    public $targetLanguageData = [];
    public $availableLocales = [];
    public $filter = 'all';

    public $languagesTable;

    // Formular-Properties
    public $showAddForm = false;
    public $newLanguageName = '';
    public $newLanguageIso = '';
    public $errorMessage = '';

    // Autotranslate SaaS Properties
    public $autoTranslateEnabled = false;
    public $showApiKeyModal = false;
    public $apiKeyInput = '';
    public $wordBalance = '---';
    public $apiErrorMessage = '';

    public function mount()
    {
        $this->languagesTable = config('ui-translation-hub.table_name', 'l7languages');
        $this->baseLocaleIso = config('app.fallback_locale', 'en');
        $this->baseLanguageData = $this->loadIso($this->baseLocaleIso);

        $this->loadAvailableLocales();

        $this->targetLocaleIso = !empty($this->availableLocales) ? array_key_first($this->availableLocales) : null;
        $this->targetLanguageData = $this->targetLocaleIso ? $this->loadIso($this->targetLocaleIso) : [];

        $savedKey = session('UI_TRANSLATION_HUB_API_KEY', config('ui-translation-hub.api_key'));
        if (!empty($savedKey)) {
            $this->apiKeyInput = $savedKey;
            $this->autoTranslateEnabled = true;
            $this->checkApiBalance();
        }
    }

    public function checkApiBalance()
    {
        if (empty($this->apiKeyInput)) {
            return;
        }

        $apiUrl = config('ui-translation-hub.api_url');

        try {
            $ch = curl_init("{$apiUrl}/api/v1/verify-key");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);

            $payload = json_encode(['api_key' => trim($this->apiKeyInput)]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Accept: application/json', 'Content-Length: ' . strlen($payload)]);

            $responseBody = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $data = json_decode($responseBody, true) ?? [];

            if ($httpCode === 200 && ($data['status'] ?? '') === 'success') {
                $balance = $data['remaining_balance'] ?? 0;
                $this->wordBalance = number_format($balance, 0, ',', '.');
            }
        } catch (\Exception $e) {
            // Silently fail balance check
        }
    }

    public function verifyApiKey()
    {
        $this->apiErrorMessage = '';

        if (empty($this->apiKeyInput)) {
            $this->apiErrorMessage = 'Bitte gib einen gültigen API-Key ein.';
            return;
        }

        $apiUrl = config('ui-translation-hub.api_url');

        try {
            session_write_close();
            $ch = curl_init("{$apiUrl}/api/v1/verify-key");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);

            $payload = json_encode(['api_key' => trim($this->apiKeyInput)]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Accept: application/json', 'Content-Length: ' . strlen($payload)]);

            $responseBody = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
                $this->apiErrorMessage = 'cURL Fehler: ' . $curlError;
                return;
            }

            $data = json_decode($responseBody, true) ?? [];

            if ($httpCode !== 200 || ($data['status'] ?? '') !== 'success') {
                $this->apiErrorMessage = $data['message'] ?? 'Ungültiger API-Key (Status: ' . $httpCode . ')';
                return;
            }

            $balance = $data['remaining_balance'] ?? 0;
            $this->wordBalance = number_format($balance, 0, ',', '.');

            session(['UI_TRANSLATION_HUB_API_KEY' => trim($this->apiKeyInput)]);
            config(['ui-translation-hub.api_key' => trim($this->apiKeyInput)]);

            $this->autoTranslateEnabled = true;
            $this->showApiKeyModal = false;
        } catch (\Exception $e) {
            $this->apiErrorMessage = 'Verbindung zum API-Server fehlgeschlagen: ' . $e->getMessage();
        }
    }

    public function disableApi()
    {
        session()->forget('UI_TRANSLATION_HUB_API_KEY');
        $this->autoTranslateEnabled = false;
        $this->wordBalance = '---';
        $this->apiKeyInput = '';
    }

    public function loadAvailableLocales()
    {
        $this->availableLocales = DB::table($this->languagesTable)->where('code', '!=', $this->baseLocaleIso)->pluck('name', 'code')->toArray();
    }

    public function loadIso($iso)
    {
        $path = lang_path("{$iso}.json");
        return File::exists($path) ? json_decode(File::get($path), true) : [];
    }

    #[On('save-key')]
    public function handleSave($key, $val)
    {
        $this->saveKey($key, $val);
    }

    public function saveKey($key, $newText)
    {
        $this->targetLanguageData[$key] = bin2hex($newText);
        $this->persistData();
    }

    private function persistData()
    {
        if (empty($this->targetLocaleIso)) {
            return;
        }

        $path = lang_path($this->targetLocaleIso . '.json');
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        File::put($path, json_encode($this->targetLanguageData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        Cache::forget("lang_json_{$this->targetLocaleIso}");
    }

    public function requestTranslation($hexKey)
    {
        if (!$this->autoTranslateEnabled) {
            return;
        }

        $apiKey = session('UI_TRANSLATION_HUB_API_KEY', config('ui-translation-hub.api_key'));
        $apiUrl = config('ui-translation-hub.api_url');

        $payloadData = [
            'api_key' => $apiKey,
            'from_code' => $this->baseLocaleIso,
            'to_code' => $this->targetLocaleIso,
            'source_hex' => $hexKey,
        ];

        try {
            $ch = curl_init("{$apiUrl}/api/v1/translate");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);

            $jsonPayload = json_encode($payloadData);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Accept: application/json', 'Content-Length: ' . strlen($jsonPayload)]);

            $responseBody = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $data = json_decode($responseBody, true) ?? [];

            if ($httpCode === 200 && isset($data['translation_hex'])) {
                $this->targetLanguageData[$hexKey] = $data['translation_hex'];

                if (isset($data['remaining_balance'])) {
                    $this->wordBalance = number_format($data['remaining_balance'], 0, ',', '.');
                }

                $this->persistData();
            }
        } catch (\Exception $e) {
            // Fehlerbehandlung
        }
    }

    public function updatedTargetLocaleIso()
    {
        if ($this->targetLocaleIso === 'ADD_NEW_LOCALE') {
            $this->showAddForm = true;
            $this->targetLocaleIso = $this->baseLocaleIso;
            return;
        }

        $this->targetLanguageData = $this->loadIso($this->targetLocaleIso);
    }

    public function translateAll()
    {
        foreach ($this->baseLanguageData as $hexKey => $value) {
            if (!isset($this->targetLanguageData[$hexKey]) || empty(hex2bin($this->targetLanguageData[$hexKey]))) {
                $this->requestTranslation($hexKey);
            }
        }
        $this->persistData();
    }

    public function addNewLanguage()
    {
        $this->errorMessage = '';
        $iso = strtolower(trim($this->newLanguageIso));
        $name = trim($this->newLanguageName);

        if (empty($iso) || empty($name)) {
            $this->errorMessage = 'Bitte alle Felder ausfüllen.';
            return;
        }

        if ($iso === $this->baseLocaleIso) {
            $this->errorMessage = 'ISO entspricht der Base-Sprache.';
            return;
        }

        $exists = DB::table($this->languagesTable)->where('code', $iso)->exists();

        if ($exists) {
            $this->errorMessage = 'ISO existiert bereits.';
            return;
        }

        DB::table($this->languagesTable)->insert([
            'name' => $name,
            'code' => $iso,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->newLanguageName = '';
        $this->newLanguageIso = '';
        $this->showAddForm = false;

        $this->loadAvailableLocales();
        $this->targetLocaleIso = $iso;
        $this->targetLanguageData = $this->loadIso($iso);
    }

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

        $this->baseLanguageData = $this->loadIso($this->baseLocaleIso);
        $this->targetLanguageData = $this->targetLocaleIso ? $this->loadIso($this->targetLocaleIso) : [];
    }
};
?>

{{-- Canvas-Dark Container --}}
<div class="p-5 rounded-sm border border-[#374151] bg-[#161426] text-[#9da4af] shadow-2xl font-sans">

    {{-- Title Header --}}
    <div class="flex justify-between items-center mb-4 pb-2 border-b border-[#374151]">
        <div class="font-black italic text-2xl tracking-tighter text-[#f8fafc] uppercase">
            Translation <span class="text-[#4f46e5]">Hub</span>
        </div>
        <div class="text-[10px] font-bold uppercase tracking-wider text-[#9da4af]">
            {{ __t('Detected Source') }}: <span
                class="text-xs text-[#4f46e5] font-black ml-1">{{ strtoupper($baseLocaleIso) }}</span>
        </div>
    </div>

    {{-- Main Toolbar (Cast Level #080e20) --}}
    <div class="flex justify-between items-center mb-6 p-3 rounded-sm border border-[#374151] bg-[#080e20]">
        <div class="flex items-center gap-3">
            <button type="button" wire:click="translateAll" wire:loading.attr="disabled"
                @if (empty($availableLocales)) disabled @endif
                class="px-3 py-2 rounded-sm text-[10px] font-bold uppercase tracking-wider text-white bg-[#4f46e5] hover:bg-[#4338ca] transition-colors disabled:opacity-40 disabled:cursor-not-allowed">
                <span wire:loading.remove wire:target="translateAll">⚡ {{ __t('Translate All Missing') }}</span>
                <span wire:loading wire:target="translateAll">Translating...</span>
            </button>

            <select wire:model.live="filter"
                class="px-3 py-1.5 rounded-sm text-[10px] font-bold uppercase bg-[#0c0b16] border border-[#374151] text-[#f8fafc] focus:border-[#4f46e5] focus:outline-none cursor-pointer">
                <option value="all">{{ __t('Show All') }}</option>
                <option value="missing">{{ __t('Missing Only') }}</option>
            </select>
        </div>

        <div class="flex items-center gap-2">
            <span class="text-[10px] font-bold uppercase text-[#9da4af]">Target:</span>
            <select wire:model.live="targetLocaleIso"
                class="px-3 py-1.5 rounded-sm text-[10px] font-bold uppercase bg-[#0c0b16] border border-[#374151] text-[#f8fafc] focus:border-[#4f46e5] focus:outline-none cursor-pointer">
                @if (empty($availableLocales))
                    <option value="">{{ __t('no Languages') }}</option>
                @else
                    @foreach ($availableLocales as $code => $name)
                        <option value="{{ $code }}">{{ strtoupper($code) }} - {{ __t($name) }}</option>
                    @endforeach
                @endif
                <optgroup label="──────────────────">
                    <option value="ADD_NEW_LOCALE" class="text-[#4f46e5] font-bold">+ {{ __t('Add new Language') }}
                    </option>
                </optgroup>
            </select>
        </div>
    </div>

    {{-- Cloud / Autotranslate Banner --}}
    <div
        class="flex justify-between items-center mb-6 p-3 rounded-sm border transition-colors duration-200 {{ $autoTranslateEnabled ? 'bg-[#4f46e5]/10 border-[#4f46e5]/40' : 'bg-[#080e20] border-[#374151]' }}">
        <div class="flex items-center gap-3">
            <label class="relative inline-flex items-center cursor-pointer select-none">
                <input type="checkbox"
                    wire:click="{{ $autoTranslateEnabled ? 'disableApi' : '$set(\'showApiKeyModal\', true)' }}"
                    @checked($autoTranslateEnabled) class="sr-only peer">
                <div
                    class="w-8 h-4 bg-[#1c1a2e] rounded-full peer peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-3 after:w-3 after:transition-all peer-checked:bg-[#4f46e5]">
                </div>
                <span class="ml-2 text-[10px] font-bold uppercase tracking-wider text-[#f8fafc]">
                    {{ $autoTranslateEnabled ? 'Cloud Translation Active' : 'Enable Cloud Autotranslate' }}
                </span>
            </label>
        </div>

        <div>
            @if ($autoTranslateEnabled)
                <span class="text-[10px] font-bold uppercase text-[#9da4af]">
                    Remaining Words: <span class="text-[#f8fafc] font-mono font-black ml-1">{{ $wordBalance }}</span>
                </span>
            @else
                <span class="text-[10px] italic text-[#9da4af]">Connect your L7 Translation Hub Account to unlock Cloud
                    Features.</span>
            @endif
        </div>
    </div>

    {{-- Translation Table --}}
    <div class="overflow-hidden rounded-sm border border-[#374151]">
        <table class="w-full text-left border-collapse table-fixed">
            <thead>
                <tr
                    class="bg-[#080e20] border-b border-[#374151] text-[10px] font-bold text-[#9da4af] uppercase tracking-wider">
                    <th class="p-3 w-[45px] text-center"></th>
                    <th class="p-3 w-[calc(40%-22.5px)]">{{ __t('Source Key / Text') }}</th>
                    <th class="p-3 w-[calc(60%-22.5px)]">{{ __t('Target Translation') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[#374151]">
                @foreach ($baseLanguageData as $key => $value)
                    @if ($filter == 'all' || !isset($targetLanguageData[$key]))
                        <tr wire:key="row-{{ $key }}" class="hover:bg-[#080e20]/60 transition-colors">
                            <td class="p-1 text-center align-top text-[8px]">
                                <button type="button" wire:click="deleteIndex('{{ $key }}')"
                                    wire:confirm="{{ __t('Do you really want to remove this key from all languages ?') }}"
                                    class="p-1 rounded-sm text-[8px] cursor-pointer opacity-40 hover:opacity-100 hover:bg-[#f43f5e]/20 
                                    border border-transparent hover:border-[#f43f5e] transition-all"
                                    title="Delete Key">❌</button>
                            </td>

                            <td class="p-3 text-[13px] text-[#9da4af] align-top break-words select-all leading-relaxed">
                                {{ hex2bin($key) }}
                            </td>

                            <td class="p-3 align-top">
                                <div class="flex gap-2 items-start w-full">
                                    <textarea id="textarea-{{ $key }}" wire:blur="saveKey('{{ $key }}', $event.target.value)"
                                        rows="2"
                                        class="w-full text-[13px] p-2 rounded-sm border border-[#374151] bg-[#0c0b16] text-[#f8fafc] 
                                        focus:border-[#4f46e5] focus:outline-none resize-y min-h-[42px]">{{ hex2bin($targetLanguageData[$key] ?? '') }}</textarea>

                                    <div class="flex flex-col gap-1 shrink-0">
                                        @if ($autoTranslateEnabled)
                                            <button type="button"
                                                wire:click="requestTranslation('{{ $key }}')"
                                                class="flex items-center justify-center rounded-sm p-1.5 bg-[#080e20] border border-[#374151] hover:border-[#4f46e5] w-8 h-8 transition-colors"
                                                title="Auto-Translate">🌐</button>
                                        @endif
                                        <button type="button"
                                            onclick="Livewire.dispatch('save-key', { key: '{{ $key }}', val: document.getElementById('textarea-{{ $key }}').value })"
                                            class="flex items-center justify-center rounded-sm p-1.5 bg-[#080e20] 
                                            cursor-pointer border-[#374151] hover:border-[#10b981] w-8 h-8 transition-colors"
                                            title="Save">✅</button>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @endif
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- Add Language Modal (Overlay Void #010413, Box Cast #080e20) --}}
    @if ($showAddForm)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-[#010413]/85 backdrop-blur-xs p-4">
            <div class="bg-[#080e20] border border-[#374151] rounded-sm w-full max-w-md p-6 shadow-2xl">
                <div class="text-sm font-black uppercase tracking-wider text-[#f8fafc] mb-4">
                    {{ __t('Add new Language') }}</div>
                <div class="flex flex-col gap-3 mb-4">
                    <div>
                        <label class="text-[9px] font-bold uppercase text-[#9da4af] block mb-1">Sprachname</label>
                        <input type="text" wire:model="newLanguageName" placeholder="z.B. Spanish"
                            class="text-xs px-3 py-2 rounded-sm border border-[#374151] bg-[#0c0b16] text-[#f8fafc] focus:border-[#4f46e5] focus:outline-none w-full" />
                    </div>
                    <div>
                        <label class="text-[9px] font-bold uppercase text-[#9da4af] block mb-1">ISO-Code</label>
                        <input type="text" wire:model="newLanguageIso" placeholder="z.B. es" maxlength="5"
                            class="text-xs px-3 py-2 rounded-sm border border-[#374151] bg-[#0c0b16] text-[#f8fafc] focus:border-[#4f46e5] focus:outline-none w-full uppercase" />
                    </div>
                    @if ($errorMessage)
                        <div class="text-[10px] text-[#f43f5e] font-bold uppercase">⚠️ {{ $errorMessage }}</div>
                    @endif
                </div>
                <div class="flex justify-end gap-2 pt-3 border-t border-[#374151]">
                    <button type="button" wire:click="$set('showAddForm', false)"
                        class="text-[10px] font-bold px-3 py-2 rounded-sm uppercase bg-[#1c1a2e] text-white hover:bg-[#374151] transition-colors">Abbrechen</button>
                    <button type="button" wire:click="addNewLanguage"
                        class="text-[10px] font-bold px-4 py-2 rounded-sm uppercase bg-[#10b981] hover:bg-[#059669] text-white transition-colors">Speichern</button>
                </div>
            </div>
        </div>
    @endif

    {{-- API Key Modal (Overlay Void #010413, Box Cast #080e20) --}}
    @if ($showApiKeyModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-[#010413]/85 backdrop-blur-xs p-4">
            <div class="bg-[#080e20] border border-[#374151] rounded-sm w-full max-w-md p-6 shadow-2xl">
                <div class="text-sm font-black uppercase tracking-wider text-[#f8fafc] mb-2">L7 Translation Hub
                    Cloud-Setup</div>
                <p class="text-xs text-[#9da4af] mb-4 leading-relaxed">Erstelle ein Projekt im Dashboard auf <a
                        href="https://hub.l7studios.de" target="_blank"
                        class="text-[#4f46e5] underline font-bold">hub.l7studios.de</a>, um deinen API-Key zu erhalten.
                </p>
                <div class="flex flex-col gap-1 mb-4">
                    <label class="text-[9px] font-bold uppercase text-[#9da4af]">Dein API-Key</label>
                    <input type="password" wire:model="apiKeyInput" placeholder="l7_live_..."
                        class="text-xs px-3 py-2 rounded-sm border border-[#374151] bg-[#0c0b16] text-[#f8fafc] focus:border-[#4f46e5] focus:outline-none w-full" />
                    @if ($apiErrorMessage)
                        <span class="text-[10px] text-[#f43f5e] font-bold uppercase mt-1">⚠️
                            {{ $apiErrorMessage }}</span>
                    @endif
                </div>
                <div class="flex justify-end gap-2 pt-3 border-t border-[#374151]">
                    <button type="button" wire:click="$set('showApiKeyModal', false)"
                        class="text-[10px] font-bold px-3 py-2 rounded-sm uppercase bg-[#1c1a2e] text-white hover:bg-[#374151] transition-colors">Abbrechen</button>
                    <button type="button" wire:click="verifyApiKey"
                        class="text-[10px] font-bold px-4 py-2 rounded-sm uppercase bg-[#4f46e5] hover:bg-[#4338ca] text-white transition-colors">Verifizieren
                        & Aktivieren</button>
                </div>
            </div>
        </div>
    @endif

</div>
