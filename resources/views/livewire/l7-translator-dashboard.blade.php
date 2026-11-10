<?php

use Livewire\Volt\Component;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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
    public $apiUrl = '';
    public $autoTranslateEnabled = false;
    public $showApiKeyModal = false;
    public $apiKeyInput = '';
    public $wordBalance = '---';
    public $apiErrorMessage = '';

    // API Debug / Radar Properties
    public $apiDebugEnabled = false;
    public $lastApiInspect = null;

    protected function getConfigPath()
    {
        return lang_path('hub-config.json');
    }

    protected function ensureConfigExists()
    {
        $path = $this->getConfigPath();
        if (!File::exists($path)) {
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            $defaultConfig = [
                'table_name' => 'l7languages',
                'api_url' => 'http://l7-translation-api-server.test/api/v1',
                'api_debug' => false,
                'api_key' => '',
                'last_known_quota' => 0,
                'status' => 'inactive',
            ];

            File::put($path, json_encode($defaultConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
    }

    protected function readHubConfig()
    {
        $this->ensureConfigExists();
        $path = $this->getConfigPath();
        return json_decode(File::get($path), true) ?? [];
    }

    protected function writeHubConfig(array $data)
    {
        $path = $this->getConfigPath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        File::put($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    //------------------------------------------------------------------------------------------------------------

    public function mount()
    {
        $configData = $this->readHubConfig();

        $this->apiUrl = $configData['api_url'] ?? 'http://l7-translation-api-server.test/api/v1';

        if (blank($this->apiUrl)) {
            throw new \RuntimeException('Translation Hub Fehler: "api_url" ist nicht konfiguriert. Bitte prüfe deine hub-config.json.');
        }

        $this->languagesTable = $configData['table_name'] ?? 'l7languages';
        $this->apiDebugEnabled = $configData['api_debug'] ?? false;

        $this->baseLocaleIso = config('app.fallback_locale');
        $this->baseLanguageData = $this->loadIso($this->baseLocaleIso);

        $this->availableLocales = DB::table($this->languagesTable)->where('code', '!=', $this->baseLocaleIso)->pluck('name', 'code')->toArray();

        $this->targetLocaleIso = !empty($this->availableLocales) ? array_key_first($this->availableLocales) : null;
        $this->targetLanguageData = $this->targetLocaleIso ? $this->loadIso($this->targetLocaleIso) : [];

        $savedKey = $configData['api_key'] ?? '';

        if (!empty($savedKey)) {
            $this->apiKeyInput = $savedKey;
            $this->autoTranslateEnabled = true;
            $this->checkApiBalance();
        }
    }
    //------------------------------------------------------------------------------------------------------------

    public function checkApiBalance()
    {
        $configData = $this->readHubConfig();
        $savedKey = $configData['api_key'] ?? '';

        if (empty($savedKey)) {
            $this->disableApi();
            return;
        }

        $fullEndpoint = rtrim($this->apiUrl, '/') . '/verify-key';

        try {
            $response = Http::timeout(5)
                ->acceptJson()
                ->asJson()
                ->post($fullEndpoint, [
                    'api_key' => $savedKey,
                ]);

            $responseData = $response->json();

            \Illuminate\Support\Facades\Log::info('checkApiBalance Request durchgeführt', [
                'endpoint' => $fullEndpoint,
                'status' => $response->status(),
                'successful' => $response->successful(),
                'response' => $responseData ?? null
            ]);

            if ($response->successful() && ($responseData['status'] ?? '') === 'success') {
                $this->autoTranslateEnabled = true;
                $this->wordBalance = $responseData['remaining_balance'] ?? 0;
                
                $configData['last_known_quota'] = $this->wordBalance;
                $configData['project_name'] = $responseData['project'] ?? 'Standard Projekt';
                $configData['status'] = 'active';
                $this->writeHubConfig($configData);
            } else {
                $this->disableApi();
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('checkApiBalance Exception', ['error' => $e->getMessage()]);

            $cachedQuota = $configData['last_known_quota'] ?? 0;
            $this->wordBalance = $cachedQuota;
            $this->autoTranslateEnabled = true;
        }
    }
    //------------------------------------------------------------------------------------------------------------

    public function verifyAndEnableApi()
    {
        $this->apiErrorMessage = '';

        if (empty($this->apiKeyInput)) {
            $this->apiErrorMessage = __t('Please enter your API Key.');
            return;
        }

        $fullEndpoint = rtrim($this->apiUrl, '/') . '/verify-key';
        $startTime = microtime(true);

        try {
            $response = Http::timeout(10)
                ->acceptJson()
                ->asJson()
                ->post($fullEndpoint, [
                    'api_key' => $this->apiKeyInput,
                ]);

            $durationMs = (int) round((microtime(true) - $startTime) * 1000);
            
            $responseData = $response->json();
            if ($responseData === null) {
                $responseData = ['raw_output' => $response->body()];
            }

            if ($this->apiDebugEnabled) {
                $this->lastApiInspect = [
                    'timestamp' => now()->format('H:i:s.v'),
                    'status' => $response->status(),
                    'successful' => $response->successful(),
                    'duration_ms' => $durationMs,
                    'request' => [
                        'method' => 'POST',
                        'url' => $fullEndpoint,
                        'payload' => ['api_key' => substr($this->apiKeyInput, 0, 6) . '...***'],
                    ],
                    'response' => $responseData,
                    'error' => $response->failed() ? "HTTP Error {$response->status()}" : null,
                ];
            }

            if ($response->successful() && ($responseData['status'] ?? '') === 'success') {
                session(['UI_TRANSLATION_HUB_API_KEY' => $this->apiKeyInput]);

                $configData = $this->readHubConfig();
                $configData['api_url'] = $this->apiUrl;
                $configData['api_key'] = $this->apiKeyInput;
                $configData['last_known_quota'] = $responseData['remaining_balance'] ?? 0;
                $configData['project_name'] = $responseData['project_name'] ?? 'Standard Projekt';
                $configData['last_checked_at'] = now()->toDateTimeString();
                $configData['status'] = 'active';

                $this->writeHubConfig($configData);

                $this->autoTranslateEnabled = true;
                $this->showApiKeyModal = false;

                if (isset($responseData['remaining_balance'])) {
                    $this->wordBalance = $responseData['remaining_balance'];
                }
            } else {
                $this->apiErrorMessage = $responseData['message'] ?? 'The route verify-key could not be found.';
            }
        } catch (\Throwable $e) {
            $durationMs = (int) round((microtime(true) - $startTime) * 1000);

            if ($this->apiDebugEnabled) {
                $this->lastApiInspect = [
                    'timestamp' => now()->format('H:i:s.v'),
                    'status' => 500,
                    'successful' => false,
                    'duration_ms' => $durationMs,
                    'request' => [
                        'method' => 'POST',
                        'url' => $fullEndpoint,
                        'payload' => ['api_key' => substr($this->apiKeyInput, 0, 6) . '...***'],
                    ],
                    'response' => [
                        'exception' => get_class($e),
                        'message' => $e->getMessage(),
                    ],
                    'error' => $e->getMessage(),
                ];
            }

            $this->apiErrorMessage = 'Verbindung zum API-Server fehlgeschlagen: ' . $e->getMessage();
        }
    }
    //------------------------------------------------------------------------------------------------------------

    public function disableApi()
    {
        session()->forget('UI_TRANSLATION_HUB_API_KEY');
        
        $configData = $this->readHubConfig();
        $configData['api_key'] = '';
        $configData['status'] = 'inactive';
        $this->writeHubConfig($configData);

        $this->autoTranslateEnabled = false;
        $this->wordBalance = '---';
        $this->apiKeyInput = '';
    }
    //------------------------------------------------------------------------------------------------------------

    public function loadAvailableLocales()
    {
        $this->availableLocales = DB::table($this->languagesTable)->where('code', '!=', $this->baseLocaleIso)->pluck('name', 'code')->toArray();
    }
    //------------------------------------------------------------------------------------------------------------

    public function loadIso($iso)
    {
        $path = lang_path("{$iso}.json");
        return File::exists($path) ? json_decode(File::get($path), true) : [];
    }
    //------------------------------------------------------------------------------------------------------------

    #[On('save-key')]
    public function handleSave($key, $val)
    {
        $this->saveKey($key, $val);
    }
    //------------------------------------------------------------------------------------------------------------

    public function saveKey($key, $newText)
    {
        $this->targetLanguageData[$key] = bin2hex($newText);
        $this->persistData();
    }
    //------------------------------------------------------------------------------------------------------------

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
    //------------------------------------------------------------------------------------------------------------

    public function requestTranslation($hexKey)
    {
        if (!$this->autoTranslateEnabled) {
            return;
        }

        $apiKey = session('UI_TRANSLATION_HUB_API_KEY');
        if (empty($apiKey)) {
            $configData = $this->readHubConfig();
            $apiKey = $configData['api_key'] ?? '';
        }

        $payloadData = [
            'api_key' => $apiKey,
            'from_code' => $this->baseLocaleIso,
            'to_code' => $this->targetLocaleIso,
            'source_hex' => $hexKey,
        ];

        $startTime = microtime(true);
        $fullEndpoint = rtrim($this->apiUrl, '/') . '/translate';

        try {
            $response = Http::timeout(10)->acceptJson()->asJson()->post($fullEndpoint, $payloadData);

            $durationMs = (int) round((microtime(true) - $startTime) * 1000);
            $httpCode = $response->status();
            $responseData = $response->json() ?? ['raw' => $response->body()];

            if ($this->apiDebugEnabled) {
                $maskedPayload = $payloadData;
                if (isset($maskedPayload['api_key'])) {
                    $maskedPayload['api_key'] = substr($maskedPayload['api_key'], 0, 6) . '...***';
                }

                $this->lastApiInspect = [
                    'timestamp' => now()->format('H:i:s.v'),
                    'status' => $httpCode,
                    'successful' => $response->successful(),
                    'duration_ms' => $durationMs,
                    'request' => [
                        'method' => 'POST',
                        'url' => $fullEndpoint,
                        'payload' => $maskedPayload,
                    ],
                    'response' => $responseData,
                    'error' => $response->failed() ? "HTTP Error {$httpCode}" : null,
                ];
            }

            if ($response->successful() && isset($responseData['translation_hex'])) {
                $hexResult = $responseData['translation_hex'];
                $plainResult = hex2bin($hexResult);

                $this->targetLanguageData[$hexKey] = $hexResult;
                $this->persistData();

                $remaining = $responseData['remaining_balance'] ?? null;
                if ($remaining !== null) {
                    $this->wordBalance = $remaining;
                    
                    $configData = $this->readHubConfig();
                    $configData['last_known_quota'] = $remaining;
                    $this->writeHubConfig($configData);

                    if ($remaining <= 0) {
                        $this->disableApi();
                    }
                }

                $this->dispatch('translation-received', key: $hexKey, translation: $plainResult, balance: $remaining);
            }
        } catch (\Throwable $e) {
            $durationMs = (int) round((microtime(true) - $startTime) * 1000);

            if ($this->apiDebugEnabled) {
                $this->lastApiInspect = [
                    'timestamp' => now()->format('H:i:s'),
                    'status' => 0,
                    'successful' => false,
                    'duration_ms' => $durationMs,
                    'request' => [
                        'method' => 'POST',
                        'url' => $fullEndpoint,
                        'payload' => $payloadData,
                    ],
                    'response' => null,
                    'error' => $e->getMessage(),
                ];
            }
        }
    }
    //------------------------------------------------------------------------------------------------------------

    public function updatedTargetLocaleIso()
    {
        if ($this->targetLocaleIso === 'ADD_NEW_LOCALE') {
            $this->showAddForm = true;
            $this->targetLocaleIso = $this->baseLocaleIso;
            return;
        }

        $this->targetLanguageData = $this->loadIso($this->targetLocaleIso);
    }
    //------------------------------------------------------------------------------------------------------------

    public function translateAll()
    {
        foreach ($this->baseLanguageData as $hexKey => $value) {
            if (!isset($this->targetLanguageData[$hexKey]) || empty(hex2bin($this->targetLanguageData[$hexKey]))) {
                $this->requestTranslation($hexKey);
            }
        }
    }
    //------------------------------------------------------------------------------------------------------------

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
    //------------------------------------------------------------------------------------------------------------

    public function deleteIndex($hexKey)
    {
        $langPath = lang_path();
        if (!File::exists($langPath)) {
            return;
        }

        $files = File::files($langPath);
        foreach ($files as $file) {
            if ($file->getExtension() === 'json' && $file->getFilename() !== 'hub-config.json') {
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
    //------------------------------------------------------------------------------------------------------------
};
?>

<div class="relative" x-data="{ balance: '{{ $wordBalance }}' }" @translation-received.window="if ($event.detail.balance !== undefined) { balance = $event.detail.balance; }">
    {{-- Full-Width Top API Radar Overlay --}}
    @if ($apiDebugEnabled)
        <div x-data="{ expanded: true }" class="fixed top-0 left-0 right-0 z-50 bg-[#010413] border-b border-[#4f46e5] shadow-2xl font-sans">

            <div class="flex items-center justify-between px-4 py-2 bg-[#161426] border-b border-[#374151]">
                <div class="flex items-center space-x-3">
                    <span class="w-3 h-3 rounded-full bg-[#4f46e5] animate-pulse"></span>
                    <span class="text-xs font-black uppercase tracking-wider text-[#f8fafc]">API Radar (Dev Log & Inspect)</span>
                </div>
                <div class="flex items-center space-x-3">
                    @if ($lastApiInspect)
                        <span class="px-2 py-0.5 text-[10px] font-bold rounded uppercase {{ ($lastApiInspect['successful'] ?? false) ? 'bg-[#10b981]/20 text-[#10b981] border border-[#10b981]/40' : 'bg-[#f43f5e]/20 text-[#f43f5e] border border-[#f43f5e]/40' }}">
                            {{ ($lastApiInspect['status'] ?? 0) === 0 ? 'FAIL' : ($lastApiInspect['status'] . ' ' . (($lastApiInspect['successful'] ?? false) ? 'OK' : 'ERR')) }}
                        </span>
                        <span class="text-xs font-mono text-[#9da4af]">{{ $lastApiInspect['duration_ms'] ?? 0 }} ms</span>
                        <span class="text-[10px] font-mono text-[#9da4af]">{{ $lastApiInspect['timestamp'] ?? '' }}</span>
                    @endif
                    <button type="button" @click="expanded = !expanded"
                        class="text-xs font-bold text-[#9da4af] hover:text-white px-3 py-1 bg-[#0c0b16] border border-[#374151] rounded cursor-pointer">
                        <span x-show="expanded">Minimieren &minus;</span>
                        <span x-show="!expanded">Taskleiste öffnen &plus;</span>
                    </button>
                </div>
            </div>

            <div x-show="expanded" x-transition class="p-4 space-y-4 max-h-[350px] overflow-y-auto bg-[#161426]/95 backdrop-blur-md">
                @if ($lastApiInspect)
                    @if (!empty($lastApiInspect['error']))
                        <div class="p-3 bg-[#f43f5e]/10 border border-[#f43f5e]/30 rounded text-xs font-mono text-[#f43f5e]">
                            ⚠️ {{ $lastApiInspect['error'] }}
                        </div>
                    @endif

                    <div>
                        <div class="text-[10px] font-bold uppercase tracking-wider text-[#9da4af] mb-1">&rarr; Request URL & Method</div>
                        <div class="bg-[#0c0b16] p-3 rounded border border-[#374151] text-xs font-mono text-[#4f46e5] break-all">
                            <span class="font-bold uppercase">[{{ $lastApiInspect['request']['method'] ?? 'POST' }}]</span> {{ $lastApiInspect['request']['url'] ?? '' }}
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <div class="text-[10px] font-bold uppercase tracking-wider text-[#9da4af] mb-1">&rarr; Outgoing Payload</div>
                            <pre class="bg-[#0c0b16] p-3 rounded border border-[#374151] text-xs font-mono text-[#f8fafc] overflow-y-auto max-h-48"><code>{{ json_encode($lastApiInspect['request']['payload'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</code></pre>
                        </div>
                        <div>
                            <div class="text-[10px] font-bold uppercase tracking-wider text-[#9da4af] mb-1">&leftarrow; Incoming Response</div>
                            <pre class="bg-[#0c0b16] p-3 rounded border border-[#374151] text-xs font-mono text-[#10b981] overflow-y-auto max-h-48"><code>{{ json_encode($lastApiInspect['response'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</code></pre>
                        </div>
                    </div>
                @else
                    <div class="py-6 text-center text-xs italic text-[#9da4af]">
                        Warte auf API-Aufrufe... (Klicke auf 🌐 bei einem String oder teste die Verifizierung)
                    </div>
                @endif
            </div>
        </div>
    @endif

    {{-- Main Canvas-Dark Container --}}
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

        {{-- Action-bar --}}
        <div
            class="flex justify-between items-center mb-8 p-3 bg-[#f7f9fb] dark:bg-[#080e20] rounded-sm border border-[#cbd5e1] dark:border-[#374151]">
            <div class="flex items-center gap-4">
                @if ($autoTranslateEnabled)
                    <button type="button" wire:click="translateAll" wire:loading.attr="disabled"
                        @if (empty($availableLocales)) disabled @endif
                        class="text-[10px] font-bold px-2 py-3 rounded-sm uppercase bg-indigo-600 hover:bg-indigo-700 text-white transition-colors disabled:opacity-50 disabled:cursor-not-allowed">
                        <span wire:loading.remove wire:target="translateAll">Translate All Missing</span>
                        <span wire:loading wire:target="translateAll">Translating...</span>
                    </button>
                @endif

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
            class="flex justify-between items-center mb-8 p-3 rounded-sm border transition-colors duration-200 {{ $autoTranslateEnabled ? 'bg-[#10b981]/10 border-[#10b981]/40 text-[#10b981]' : 'bg-[#f59e0b]/10 border-[#f59e0b]/40 text-[#f59e0b]' }}">
            <div class="flex items-center gap-3">
                <label class="relative inline-flex items-center cursor-pointer select-none">
                    <input type="checkbox"
                        wire:click="{{ $autoTranslateEnabled ? 'disableApi' : '$set(\'showApiKeyModal\', true)' }}"
                        @checked($autoTranslateEnabled) class="sr-only peer">
                    <div
                        class="w-8 h-4 bg-[#1c1a2e] rounded-full peer peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-3 after:w-3 after:transition-all {{ $autoTranslateEnabled ? 'peer-checked:bg-[#10b981]' : 'bg-[#f59e0b]' }}">
                    </div>
                    <span class="ml-2 text-[10px] font-black uppercase tracking-wider {{ $autoTranslateEnabled ? 'text-[#10b981]' : 'text-[#f59e0b]' }}">
                        {{ $autoTranslateEnabled ? 'Cloud Translation Active' : 'Cloud Translation Inactive (Setup Required)' }}
                    </span>
                </label>
            </div>

            <div>
                @if ($autoTranslateEnabled)
                    <span class="text-[10px] font-bold uppercase text-[#9da4af]">
                        Remaining chars: <span
                            class="text-[#10b981] font-mono font-black ml-1 text-lg" x-text="balance"></span>
                    </span>
                @else
                    <span class="text-[10px] italic text-[#f59e0b]">Connect your L7 Translation Hub Account to unlock Cloud Features.</span>
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
                                <td class="p-3 text-center align-top">
                                    <button type="button" wire:click="deleteIndex('{{ $key }}')"
                                        wire:confirm="{{ __t('Do you really want to remove this key from all languages ?') }}"
                                        class="flex items-center justify-center rounded-sm p-1.5 bg-[#080e20] border border-[#374151] hover:border-[#f43f5e] hover:bg-[#f43f5e]/20 text-[#9da4af] hover:text-[#f43f5e] w-8 h-8 transition-colors cursor-pointer"
                                        title="{{ __t('Delete Key') }}">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 0 1 0 0-1-1h-4a1 0 1 0 0 1 1v3M4 7h16"></path>
                                        </svg>
                                    </button>
                                </td>

                                <td class="p-3 align-top">
                                    <textarea rows="2" disabled
                                        class="w-full text-[13px] p-2 rounded-sm border border-[#374151] bg-[#0c0b16] text-[#9da4af] focus:outline-none resize-y min-h-[42px] cursor-not-allowed opacity-90">{{ hex2bin($key) }}</textarea>
                                </td>

                                <td class="p-3 align-top" 
                                    wire:key="translation-cell-{{ $key }}"
                                    x-data="{ 
                                        dirty: false, 
                                        saved: false,
                                        text: @js(isset($targetLanguageData[$key]) ? hex2bin($targetLanguageData[$key]) : '')
                                    }"
                                    @translation-received.window="if ($event.detail.key === '{{ $key }}') { text = $event.detail.translation; saved = true; dirty = false; }">
                                    <div class="flex gap-2 items-start w-full">
                                        <textarea id="textarea-{{ $key }}" 
                                            x-model="text"
                                            x-on:input="dirty = true; saved = false;"
                                            rows="2"
                                            class="w-full text-[13px] p-2 rounded-sm border border-[#374151] bg-[#0c0b16] text-[#f8fafc] focus:border-[#4f46e5] focus:outline-none resize-y min-h-[42px]"></textarea>

                                        <div class="flex flex-col gap-1 shrink-0">
                                            @if ($autoTranslateEnabled)
                                                <button type="button"
                                                    wire:click="requestTranslation('{{ $key }}')"
                                                    class="flex items-center justify-center rounded-sm p-1.5 bg-[#080e20] border border-[#374151] hover:border-[#4f46e5] hover:bg-[#4f46e5]/20 text-[#9da4af] hover:text-[#4f46e5] w-8 h-8 transition-colors cursor-pointer"
                                                    title="Auto-Translate">🌐</button>
                                            @endif

                                            <button type="button"
                                                wire:click="saveKey('{{ $key }}', text)"
                                                @click="dirty = false; saved = true;"
                                                :class="dirty ? 'bg-[#080e20] border border-[#10b981] text-[#10b981] hover:bg-[#10b981]/20 hover:text-[#10b981] hover:border-[#10b981]' : (saved ? 'bg-[#10b981]/10 border border-[#10b981]/40 text-[#10b981]' : 'bg-[#080e20] border border-[#374151] text-[#9da4af] hover:border-[#10b981] hover:bg-[#10b981]/20 hover:text-[#10b981]')"
                                                class="flex items-center justify-center rounded-sm p-1.5 w-8 h-8 transition-colors cursor-pointer shadow-sm"
                                                title="Save">
                                                ✓
                                            </button>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Add Language Modal --}}
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

        {{-- API Key Modal --}}
        @if ($showApiKeyModal)
            <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-xs">
                <div class="bg-[#161426] border border-[#374151] rounded-sm w-full max-w-md p-6 shadow-2xl font-sans">
                    <div class="text-sm font-black uppercase tracking-wider text-white mb-2">
                        {{ __t('L7 Translation Hub Cloud-Setup') }}
                    </div>

                    <p class="text-[11px] text-[#9da4af] mb-4 leading-relaxed">
                        {{ __t('Gib deinen API-Key ein, um die automatische Cloud-Übersetzung zu aktivieren.') }}
                    </p>

                    <div
                        class="mb-5 p-3 rounded-sm bg-[#080e20] border border-[#374151] flex items-center justify-between gap-3">
                        <div class="flex flex-col">
                            <span
                                class="text-[10px] font-bold text-white uppercase">{{ __t("Don't have a Key?") }}</span>
                            <span
                                class="text-[9px] text-[#9da4af]">{{ __t('Register for Free & Secure Your Quota.') }}</span>
                        </div>
                        <a href="{{ rtrim($apiUrl, '/') }}/register" target="_blank" rel="noopener noreferrer"
                            class="link shrink-0 text-[10px] font-black uppercase px-3 py-1.5 rounded-sm bg-[#4f46e5] hover:bg-indigo-500 text-white transition-colors shadow-sm">
                            &rarr; {{ __t('Get Free Key') }}
                        </a>
                    </div>

                    <div class="flex flex-col gap-1 mb-5">
                        <label class="text-[9px] font-bold uppercase text-[#9da4af]">{{ __t('Dein API-Key') }}</label>
                        <input type="password" wire:model="apiKeyInput" placeholder="l7_live_..."
                            class="text-xs px-3 py-2 rounded-sm border border-[#374151] bg-[#0c0b16] text-[#f8fafc] focus:border-[#4f46e5] focus:outline-none w-full" />
                        @if ($apiErrorMessage)
                            <span class="text-[10px] text-[#f43f5e] font-bold uppercase mt-1">⚠️
                                {{ $apiErrorMessage }}</span>
                        @endif
                    </div>

                    <div class="flex justify-end gap-2 pt-3 border-t border-[#374151]">
                        <button type="button" wire:click="$set('showApiKeyModal', false)"
                            class="text-[10px] font-bold px-3 py-2 rounded-sm uppercase bg-[#1c1a2e] hover:bg-[#374151] text-white transition-colors">
                            {{ __t('Abbrechen') }}
                        </button>
                        <button type="button" wire:click="verifyAndEnableApi"
                            class="text-[10px] font-bold px-4 py-2 rounded-sm uppercase bg-indigo-600 hover:bg-indigo-500 text-white transition-colors shadow-sm">
                            {{ __t('Verifizieren & Aktivieren') }}
                        </button>
                    </div>
                </div>
            </div>
        @endif

    </div>
</div>