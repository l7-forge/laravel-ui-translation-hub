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

        // Regex fängt class="klassen hier" und class='klassen hier' ein
        // Berücksichtigt auch Zeilenumbrüche innerhalb von class-Attributen
        preg_match_all('/class=["\']([^"\']+)["\']/is', $content, $matches);

        if (!empty($matches[1])) {
            foreach ($matches[1] as $classString) {
                // Eventuelle Blade-Echos wie {{ ... }} oder Linebreaks säubern
                $cleanedString = preg_replace('/\{\{[^\|\}]+\}\}/', ' ', $classString);
                $cleanedString = str_replace(["\r", "\n", "\t"], ' ', $cleanedString);
                
                // In einzelne Klassen zerlegen
                $classes = explode(' ', $cleanedString);
                
                foreach ($classes as $class) {
                    $class = trim($class);
                    echo "Class: {$class}...\n";
                    // Nur echte Klassen sammeln (keine leeren Strings oder Reste von Ternaries)
                    if ($class !== ''){
                        // 1. Wenn es ein Fragezeichen enthält, ist es PHP/Ternary -> überspringen
                            if (str_contains($class, '?')) {
                                continue;
                            }
                            // 2. Ein Doppelpunkt ist nur verboten, wenn er isoliert als PHP-Operator steht.
                            // Wenn er direkt an Buchstaben klebt (wie bei dark: oder hover:), lassen wir ihn durch!
                            if (str_contains($class, ':')) {
                                // Falls es sich um einen reinen PHP-Ternary-Doppelpunkt handelt (isoliert)
                                if ($class === ':') {
                                    continue;
                                }
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
$outputContent .= "======================================================================\n";
$outputContent .= "--}}\n\n";
$outputContent .= "<div class=\"hidden\">\n";

// Wir klatschen die Klassen in Blöcken rein, damit es lesbar bleibt
$chunks = array_chunk($uniqueClasses, 10);
foreach ($chunks as $chunk) {
    $outputContent .= "    <span class=\"" . implode(' ', $chunk) . "\"></span>\n";
}

$outputContent .= "</div>\n";

// Datei schreiben
file_put_contents($outputFile, $outputContent);

echo "Erfolgreich! " . count($uniqueClasses) . " einzigartige Klassen in {$outputFile} exportiert.\n";