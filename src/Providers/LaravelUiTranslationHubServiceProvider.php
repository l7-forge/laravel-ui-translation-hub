<?php

namespace L7Studios\LaravelUiTranslationHub\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Livewire\Volt\Volt;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use L7Studios\LaravelUiTranslationHub\Middleware\SetLocaleFromSession;

//php artisan optimize:clear
class LaravelUiTranslationHubServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Holt den HTTP-Kernel der App und registriert die Middleware in der 'web' Gruppe
        $kernel = $this->app->make(HttpKernel::class);
        $kernel->appendMiddlewareToGroup('web', SetLocaleFromSession::class);

        // Nutze den Tabellennamen aus der Config
        $tableName = config('ui-translation-hub.table_name', 'l7languages');
        $this->tableExists($tableName);

        // =========================================================================
        // AUTOMATISCHES RE-PUBLISH DES FUTTERS BEI UPDATES (NUR IM DEV-MODUS)
        // =========================================================================
        if (config('app.env') !== 'production') {
            $sourceFutter = dirname(__DIR__, 2) . '/resources/views/styles.blade.php';
            $targetFutter = resource_path('views/vendor/laravel-ui-translation-hub/styles.blade.php');

            // Falls die Datei beim Nutzer fehlt oder veraltet ist (andere Dateigröße/Zeitstempel),
            // spiegeln wir sie eiskalt und automatisch rüber!
            if (file_exists($sourceFutter)) {
                if (!file_exists($targetFutter) || md5_file($sourceFutter) !== md5_file($targetFutter)) {
                    if (!is_dir(dirname($targetFutter))) {
                        mkdir(dirname($targetFutter), 0755, true);
                    }
                    copy($sourceFutter, $targetFutter);
                }
            }
        }

        // Adjust Livewire/Volt Paths
        $this->app->booted(function () {
            // 1. Hole die vorhandenen Pfade (falls doch mal was drin sein sollte)
            $currentPaths = collect(\Livewire\Volt\Volt::paths())->map(function ($path) {
                return is_object($path) ? $path->path : $path;
            })->toArray();

            // 2. Bestimme deinen Package-Pfad sauber via dirname()
            $packagePath = dirname(__DIR__, 2) . '/resources/views/livewire';

            // 3. Wenn die Liste leer ist, setzen wir den Standard-App-Pfad als Fundament
            if (empty($currentPaths)) {
                $currentPaths = [
                    resource_path('views/livewire')
                ];
            }

            // 4. Jetzt fügen wir deinen Package-Pfad hinzu und filtern Duplikate
            $mergedPaths = array_unique(array_merge($currentPaths, [$packagePath]));

            // 5. Zurückschreiben an Volt
            \Livewire\Volt\Volt::mount($mergedPaths);
        });
        // 1. Config
        // Diese scripts werden nie aufgerufen, composer 
        // fuehrt nie component scripts aus

        if ($this->app->runningInConsole()) {
            $this->publishes([
                dirname(__DIR__, 2) . '/config/ui-translation-hub.php'
                => config_path('ui-translation-hub.php'),
            ], 'ui-translation-hub-config');

            //2. styles dummy (--force)
            $this->publishes([
                dirname(__DIR__, 2) . '/resources/views/styles.blade.php'
                => resource_path('views/vendor/laravel-ui-translation-hub/styles.blade.php'),
            ], 'ui-translation-styles');

            // 3. Language Switcher UI
            $this->publishes([
                dirname(__DIR__, 2) . '/resources/views/livewire/ui-language-switcher.blade.php'
                => resource_path('views/vendor/laravel-ui-translation-hub/ui-language-switcher.blade.php'),
            ], 'ui-language-switcher');
        }
    }

    public function tableExists($languageTableName)
    {
        // 1. Prüfen, ob die Tabelle bereits existiert
        if (!Schema::hasTable($languageTableName)) {
            //Alle .json files im lang folder loeschen
            $langPath = lang_path();
            if (File::exists($langPath)) {
                // Holt alle Dateien direkt aus dem lang/-Verzeichnis
                $files = File::files($langPath);

                foreach ($files as $file) {
                    // Nur .json-Dateien löschen (z.B. en.json, es.json)
                    if ($file->getExtension() === 'json') {
                        File::delete($file->getPathname());
                    }
                }
            }
            // 2. Wenn nicht, Tabelle dynamisch erstellen
            Schema::create($languageTableName, function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('code')->unique();
                $table->tinyInteger('status')->default(1)->comment('0-inactive, 1-active');
                $table->timestamps(); // Optional, aber in Laravel Standard
            });

            // 3. Die Standard-Zeile (Englisch) als Initialwert einfügen
            DB::table($languageTableName)->insert([
                'id' => 1,
                'name' => 'Default',
                'code' => env('APP_FALLBACK_LOCALE'),
                'status' => 1,
            ]);

            return false; // Signalisiert: Tabelle existierte nicht, wurde neu angelegt
        }

        return true; // Signalisiert: Tabelle existierte bereits
    }
    public function register(): void
    {
        $this->mergeConfigFrom(
            dirname(__DIR__, 2) . '/config/ui-translation-hub.php',
            'ui-translation-hub'
        );

        $this->app->singleton('custom_translator', function () {
            return new class {
                public function translate($hexKey)
                {
                    $locale = app()->getLocale();
                    $fallback = config('app.fallback_locale');

                    // 1. Cache laden
                    $cacheKey = "lang_json_{$locale}";
                    $translations = Cache::remember($cacheKey, now()->addDay(), function () use ($locale) {
                        $path = lang_path("{$locale}.json");
                        return File::exists($path) ? json_decode(File::get($path), true) : [];
                    });

                    // 2. Collector: Registriere neue Keys nur in der Basis-Sprache (für Dev-Umgebung)
                    if (config('app.env') !== 'production') {
                        $this->autoRegisterKey($hexKey);
                    }

                    // 3. Logik: Rückgabe der Übersetzung oder Markierung des Originals
                    $originalText = hex2bin($hexKey);

                    if (isset($translations[$hexKey]) && !empty(hex2bin($translations[$hexKey]))) {
                        return hex2bin($translations[$hexKey]);
                    }

                    if ($locale !== $fallback) {
                        return $originalText . ' *';
                    }

                    return $originalText;
                }

                protected function autoRegisterKey($hexKey)
                {
                    $baseLocale = config('app.fallback_locale', app()->getLocale());
                    $path = lang_path("{$baseLocale}.json");
                    $data = File::exists($path) ? json_decode(File::get($path), true) : [];

                    if (!isset($data[$hexKey])) {
                        $data[$hexKey] = $hexKey;

                        if (!is_dir(dirname($path))) {
                            mkdir(dirname($path), 0755, true);
                        }

                        File::put($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                        Cache::forget("lang_json_{$baseLocale}");
                    }
                }
            };
        });
    }
}
