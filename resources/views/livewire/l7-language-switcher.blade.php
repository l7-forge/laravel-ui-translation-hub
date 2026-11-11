<?php

use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Cache;

new class extends Component {
    public $currentLocale;
    public $languages = [];

    public function mount()
    {
        // Aktuelle Sprache ermitteln (aus Session oder Fallback)
        $this->currentLocale = Session::get('locale', config('app.locale', config('app.fallback_locale', 'en')));

        // Tabellenname aus der Package-Config holen
        $tableName = config('ui-translation-hub.table_name', 'l7languages');

        // Alle aktiven Sprachen aus der DB laden
        if (Schema::hasTable($tableName)) {
            $this->languages = DB::table($tableName)
                ->select(['code', 'name'])
                ->orderBy('name', 'asc')
                ->get()
                ->toArray();
        }
    }

    public function switchLanguage($locale)
    {
        $tableName = config('ui-translation-hub.table_name', 'l7languages');
        
        // Korrekte Prüfung auf Existenz oder Fallback-Sprache
        $exists = DB::table($tableName)->where('code', $locale)->exists() || $locale === config('app.fallback_locale');

        if ($exists) {
            Cache::forget("lang_json_{$locale}");

            // Sprache sauber in der Session speichern und persistieren
            session(['locale' => $locale]);
            session()->save(); 
            
            app()->setLocale($locale);
            
            // Seite neu laden, damit die Session/Middleware greift
            $this->js('window.location.reload();');
        }
    }
};
?>

<div class="inline-block text-left">
    <select wire:change="switchLanguage($event.target.value)"
        class="appearance-none cursor-pointer text-[11px] font-bold pr-8 px-2.5 py-1.5 rounded-sm uppercase tracking-wider
        border-[#cbd5e1] dark:border-[#374151] 
        bg-[#ffffff] dark:bg-[#161426] 
        text-gray-700 dark:text-[#9da4af]
        focus:outline-none focus:ring-1 focus:ring-indigo-500">
        
        {{-- Base Language / Fallback immer als Option anbieten falls nicht in DB --}}
        @php 
            $fallbackIso = config('app.fallback_locale', 'en');
            $hasFallbackInList = collect($this->languages)->contains('code', $fallbackIso);
        @endphp
        
        @if(!$hasFallbackInList)
            <option value="{{ $fallbackIso }}" @selected($currentLocale === $fallbackIso)>
                {{ strtoupper($fallbackIso) }}
            </option>
        @endif

        @foreach ($languages as $lang)
            <option value="{{ $lang->code }}" @selected($currentLocale === $lang->code)>
                {{ $lang->name }} ({{ strtoupper($lang->code) }})
            </option>
        @endforeach
    </select>
</div>
```[cite: 5]