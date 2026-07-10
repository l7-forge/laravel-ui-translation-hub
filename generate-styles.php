<?php

// Pfade definieren (Anpassen, falls deine Ordnerstruktur abweicht)
$viewsDir = __DIR__ . '/resources/views';
$outputFile = __DIR__ . '/resources/views/styles.blade.php';

if (!is_dir($viewsDir)) {
    die("Fehler: Verzeichnis {$viewsDir} existiert nicht!\n");
}

echo "Scanne Views in: {$viewsDir}...\n";

// Alle Dateien im Ordner rekursiv durchsuchen
$directory = new RecursiveDirectoryIterator($viewsDir);
$iterator = new RecursiveIteratorIterator($directory);

$allClasses = [];

foreach ($iterator as $file) {
    // Sicherstellen, dass es eine echte Datei ist und auf .blade.php endet
    if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
        $filePath = $file->getRealPath();
        
        // Die styles.blade.php selbst natürlich nicht mitscannen!
        if (basename($filePath) === 'styles.blade.php') {
            continue;
        }

        echo "Scanne View: {$file->getFilename()}...\n";
        $content = file_get_contents($filePath);

        // Vorab-Säuberung: Zeilenumbrüche und Tabs innerhalb der Datei durch Leerzeichen ersetzen,
        // damit mehrzeilige class-Attribute fehlerfrei von der Regex erfasst werden.
        $contentNormalized = str_replace(["\r", "\n", "\t"], ' ', $content);

        // NEUE UNBRENNBARE REGEX: Trennt sauber nach äußeren doppelten ODER einfachen Quotes,
        // damit innere Quotes in eckigen Klammern (z.B. after:content-['']) ignoriert werden.
        preg_match_all('/class="([^"]+)"|class=\'([^\']+)\'/i', $contentNormalized, $matches);

        if (!empty($matches[0])) {
            foreach ($matches[0] as $index => $fullMatch) {
                // Da wir zwei Gruppen haben (1 für ", 2 für '), holen wir den befüllten String heraus
                $classString = !empty($matches[1][$index]) ? $matches[1][$index] : $matches[2][$index];

                // Blade-Echos komplett entfernen, um keine Fragmente zu erhalten
                $cleanedString = preg_replace('/\{\{[^\|\}]+\}\}/', ' ', $classString);
                
                // In einzelne Klassen zerlegen
                $classes = explode(' ', $cleanedString);
                
                foreach ($classes as $class) {
                    $class = trim($class);
                    
                    if ($class !== '') {
                        // FILTER 1: Überspringe Code-Fragmente und Operatoren
                        if (
                            str_contains($class, '?') || 
                            str_contains($class, '$') || 
                            str_contains($class, '{') || 
                            str_contains($class, '}') || 
                            $class === ':'
                        ) {
                            continue;
                        }

                        // FILTER 2: Verhindere unvollständige eckige Klammern
                        if (str_contains($class, '[') && !str_contains($class, ']')) {
                            continue;
                        }
                        if (str_contains($class, ']') && !str_contains($class, '[')) {
                            continue;
                        }

                        echo "Class: {$class}...\n";
                        $allClasses[] = $class;
                    }
                }
            }
        }
    }
}

// Duplikate eliminieren und alphabetisch sortieren für die Optik
$uniqueClasses = array_unique($allClasses);
sort($uniqueClasses);

if (empty($uniqueClasses)) {
    die("Keine Tailwind-Klassen in den Views gefunden.\n");
}

// Inhalt für die styles.blade.php generieren
$outputContent = "";
$outputContent .= "{{--\n";
$outputContent .= "======================================================================\n";
$outputContent .= "GENERATED TAILWIND CLASSES FOR LARAVEL UI TRANSLATION HUB\n";
$outputContent .= "Diese Datei dient nur als Futter für den lokalen Tailwind-Compiler.\n";
$outputContent .= "Time: " . date('Y-m-d H:i:s') . "\n"; // Bleibt bei date(), falls das Skript standalone via CLI läuft
$outputContent .= "======================================================================\n";
$outputContent .= "--}}\n\n";
$outputContent .= "<div class=\"hidden\" style=\"display: none !important;\">\n";

// Jede Klasse isoliert in ein eigenes span-Tag packen, um CSS-Konflikte zu vermeiden
foreach ($uniqueClasses as $class) {
    $outputContent .= "    <span class=\"{$class}\"></span>\n";
}

$outputContent .= "</div>\n";

// Datei schreiben
file_put_contents($outputFile, $outputContent);

echo "Erfolgreich! " . count($uniqueClasses) . " einzigartige Klassen in {$outputFile} exportiert.\n";