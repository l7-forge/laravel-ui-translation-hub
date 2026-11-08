<?php

namespace L7Labs\LaravelUiTranslationHub\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Livewire\Volt\Volt;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use L7Labs\LaravelUiTranslationHub\Middleware\SetLocaleFromSession;

class LaravelUiTranslationHubServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Holt den HTTP-Kernel der App und registriert die Middleware in der 'web' Gruppe
        $kernel = $this->app->make(HttpKernel::class);
        $kernel->appendMiddlewareToGroup('web', SetLocaleFromSession::class);

        // Tabellennamen direkt aus der hub-config.json laden (oder Fallback)
        $configPath = lang_path('hub-config.json');
        $tableName = 'l7languages';
        if (File::exists($configPath)) {
            $configData = json_decode(File::get($configPath), true);
            $tableName = $configData['table_name'] ?? 'l7languages';
        }
        
        $this->tableExists($tableName);

        $this->app->booted(function () {
            $currentPaths = collect(\Livewire\Volt\Volt::paths())->map(function ($path) {
                return is_object($path) ? $path->path : $path;
            })->toArray();

            $packagePath = dirname(__DIR__, 2) . '/resources/views/livewire';

            if (empty($currentPaths)) {
                $currentPaths = [
                    resource_path('views/livewire')
                ];
            }

            $mergedPaths = array_unique(array_merge($currentPaths, [$packagePath]));
            \Livewire\Volt\Volt::mount($mergedPaths);
        });

        // Nur noch die Volt-Views publishen (keine Configs mehr nötig)
        if ($this->app->runningInConsole()) {
            $this->publishes([
                dirname(__DIR__, 2) . '/resources/views/livewire/l7-language-switcher.blade.php'
                => resource_path('views/livewire/l7-language-switcher.blade.php'),
            ], 'l7-language-switcher');

            $this->publishes([
                dirname(__DIR__, 2) . '/resources/views/livewire/l7-translator-dashboard.blade.php'
                => resource_path('views/livewire/l7-translator-dashboard.blade.php'),
            ], 'l7-translator-dashboard');
        }
    }

    public function tableExists($languageTableName)
    {
        if (!Schema::hasTable($languageTableName)) {
            $langPath = lang_path();
            if (File::exists($langPath)) {
                $files = File::files($langPath);

                foreach ($files as $file) {
                    if ($file->getExtension() === 'json' && $file->getFilename() !== 'hub-config.json') {
                        File::delete($file->getPathname());
                    }
                }
            }

            Schema::create($languageTableName, function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('code')->unique();
                $table->tinyInteger('status')->default(1)->comment('0-inactive, 1-active');
                $table->timestamps();
            });

            DB::table($languageTableName)->insert([
                'id' => 1,
                'name' => 'Default',
                'code' => env('APP_FALLBACK_LOCALE', 'en'),
                'status' => 1,
            ]);

            return false;
        }

        return true;
    }

    public function register(): void
    {
        // 1. Unser Custom Translator (der die JSON-Dateien und das Caching verwaltet)
        $this->app->singleton('custom_translator', function () {
            return new class {
                public function translate($hexKey)
                {
                    $locale = app()->getLocale();
                    $fallback = config('app.fallback_locale');

                    $cacheKey = "lang_json_{$locale}";
                    $translations = Cache::remember($cacheKey, now()->addDay(), function () use ($locale) {
                        $path = lang_path("{$locale}.json");
                        return File::exists($path) ? json_decode(File::get($path), true) : [];
                    });

                    if (config('app.env') !== 'production') {
                        $this->autoRegisterKey($hexKey);
                    }

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

        // 2. WICHTIG: Das direkte Decorating des originalen Laravel-Translators
        $this->app->extend('translator', function ($originalTranslator, $app) {
            return new class($originalTranslator) implements \Illuminate\Contracts\Translation\Translator {
                protected $originalTranslator;

                public function __construct($originalTranslator)
                {
                    $this->originalTranslator = $originalTranslator;
                }

                public function get($key, array $replace = [], $locale = null, $fallback = true)
                {
                    $fallbackLocale = config('app.fallback_locale');

                    // Wenn Laravel in der Fallback-Sprache einen Eintrag hat:
                    if ($this->originalTranslator->has($key, $fallbackLocale)) {
                        // Rohen Text aus der Fallback-Sprache holen (ohne Replacements)
                        $rawFallbackText = $this->originalTranslator->get($key, [], $fallbackLocale);

                        // Kanonisieren (:username -> __VAR1__) für den sauberen Master-Index
                        $canonicalKey = canonicalize_translation_key($rawFallbackText);

                        // Direkt in unsere __t() Engine übergeben!
                        return __t($canonicalKey, $replace, $locale);
                    }

                    // Ansonsten Originales Resultat von Laravel zurückliefern
                    return $this->originalTranslator->get($key, $replace, $locale, $fallback);
                }

                public function choice($key, $number, array $replace = [], $locale = null)
                {
                    return $this->originalTranslator->choice($key, $number, $replace, $locale);
                }

                public function getLocale()
                {
                    return $this->originalTranslator->getLocale();
                }

                public function setLocale($locale)
                {
                    $this->originalTranslator->setLocale($locale);
                }
            };
        });
    }
}