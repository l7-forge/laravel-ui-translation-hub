namespace TranslationHub\Services;
<?php
use Illuminate\Contracts\Translation\Translator as TranslatorContract;

class TranslationHubTranslatorDecorator implements TranslatorContract
{
    protected TranslatorContract $originalTranslator;

    public function __construct(TranslatorContract $originalTranslator)
    {
        $this->originalTranslator = $originalTranslator;
    }

    public function get($key, array $replace = [], $locale = null, $fallback = true)
    {
        $fallbackLocale = config('app.fallback_locale');

        // WENN Laravel in der Fallback-Sprache einen Eintrag hat:
        if ($this->originalTranslator->has($key, $fallbackLocale)) {
            
            // Hole den Text/String aus der Fallback-Sprache
            $fallbackText = $this->originalTranslator->get($key, [], $fallbackLocale);

            // Direkt komplett in den Collector übergeben und durch __t() schleusen!
            return __t($fallbackText, $replace, $locale);
        }

        // GIBT ES KEINE: Originales Resultat von Laravel zurückliefern
        return $this->originalTranslator->get($key, $replace, $locale, $fallback);
    }

    // --- Delegation der übrigen Interface-Methoden ---

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
}