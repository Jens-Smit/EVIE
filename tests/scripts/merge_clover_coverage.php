<?php

/**
 * Fuehrt mehrere PHPUnit-Clover-Reports (eine Datei pro Testsuite) zu einem
 * einzigen Gesamt-Report coverage-merged.xml zusammen. Jede Suite geht damit
 * in die Testabdeckungsrate ein (Blueprint: Unit + Integration + E2E).
 *
 * Zusammenfuehrungs-Regel:
 *  - Zeilen-Coverage: count = max aller Reports (Zeile gilt als ausgefuehrt,
 *    sobald irgendeine Suite sie getroffen hat).
 *  - Datei-/Klassen-/Projekt-Metriken: neu aggregiert aus den gesetzten
 *    count-Attributen (coveredstatements = Anzahl Zeilen mit count > 0).
 *
 * Vorgehen: Alle Reports instrumentieren denselben src/-Baum und haben daher
 * identische Datei-/Zeilen-Struktur. Es wird die Struktur des ersten Reports
 * als Vorlage verwendet, die count-Attribute werden pro (Datei, Zeile) auf
 * das Maximum aller Reports gesetzt. So entfaellt importNode und damit jede
 * Live-DOM-Interferenz; die Metriken werden danach garantiert konsistent neu
 * berechnet.
 *
 * Aufruf:
 *   php tests/scripts/merge_clover_coverage.php                 # merge -> coverage-merged.xml
 *   php tests/scripts/merge_clover_coverage.php --summary DATEI # nur Metriken ausgeben
 *
 * Keine externe Abhaengigkeit; nur PHP-Standard (DOMDocument).
 */

declare(strict_types=1);

(function (array $argv): void {
    $summaryOnly = false;
    $summaryFile = '';
    for ($i = 1; $i < count($argv); $i++) {
        if ($argv[$i] === '--summary') {
            $summaryOnly = true;
            $summaryFile = $argv[$i + 1] ?? '';
            $i++;
        }
    }

    if ($summaryOnly) {
        if ($summaryFile === '' || !is_file($summaryFile)) {
            fwrite(STDERR, "Fehler: Clover-Datei fehlt oder nicht lesbar: {$summaryFile}\n");
            exit(1);
        }
        $doc = loadDoc($summaryFile);
        recomputeMetrics($doc);
        printSummary($doc);
        exit(0);
    }

    $reports = glob(__DIR__ . '/../../coverage-*.xml') ?: [];
    $reports = array_values(array_filter($reports, static fn (string $f) => basename($f) !== 'coverage-merged.xml'));

    if ($reports === []) {
        fwrite(STDERR, "Keine coverage-*.xml-Reports gefunden.\n");
        exit(1);
    }

    // Struktur-Vorlage = erster Report.
    $merged = loadDoc($reports[0]);

    // Aggregat: name -> [lineNum => maxCount].
    $maxCounts = [];
    foreach ($reports as $report) {
        $doc = loadDoc($report);
        foreach ($doc->getElementsByTagName('file') as $file) {
            $name = $file->getAttribute('name');
            if (!isset($maxCounts[$name])) {
                $maxCounts[$name] = [];
            }
            foreach ($file->getElementsByTagName('line') as $line) {
                $num = (int) $line->getAttribute('num');
                $count = (int) $line->getAttribute('count');
                if ($count > ($maxCounts[$name][$num] ?? 0)) {
                    $maxCounts[$name][$num] = $count;
                }
            }
        }
    }

    // Aggregat auf Vorlage anwenden.
    foreach ($merged->getElementsByTagName('file') as $file) {
        $name = $file->getAttribute('name');
        $counts = $maxCounts[$name] ?? [];
        foreach ($file->getElementsByTagName('line') as $line) {
            $num = (int) $line->getAttribute('num');
            $line->setAttribute('count', (string) ($counts[$num] ?? 0));
        }
    }

    recomputeMetrics($merged);

    $outPath = __DIR__ . '/../../coverage-merged.xml';
    $merged->formatOutput = true;
    $merged->save($outPath);

    fwrite(STDOUT, "Coverage-Reports gemerged: " . count($reports) . " -> {$outPath}\n\n");
    printSummary($merged);
})($argv);

function loadDoc(string $file): DOMDocument
{
    $doc = new DOMDocument();
    $doc->preserveWhiteSpace = false;
    if (!$doc->load($file)) {
        fwrite(STDERR, "Fehler: Konnte {$file} nicht laden.\n");
        exit(1);
    }
    return $doc;
}

function recomputeMetrics(DOMDocument $doc): void
{
    $project = $doc->getElementsByTagName('project')->item(0);
    if ($project === null) {
        return;
    }

    $totalFiles = 0;
    $totalLoc = 0;
    $totalNcloc = 0;
    $totalClasses = 0;
    $totalMethods = 0;
    $coveredMethods = 0;
    $totalStatements = 0;
    $coveredStatements = 0;

    foreach ($doc->getElementsByTagName('file') as $file) {
        $totalFiles++;

        $class = $file->getElementsByTagName('class')->item(0);
        if ($class === null) {
            continue;
        }
        $totalClasses++;

        $classMethods = 0;
        $classCoveredMethods = 0;
        $classStmts = 0;
        $classCoveredStmts = 0;

        foreach ($file->getElementsByTagName('line') as $line) {
            $type = $line->getAttribute('type');
            $count = (int) $line->getAttribute('count');
            if ($type === 'method') {
                $classMethods++;
                $totalMethods++;
                if ($count > 0) {
                    $classCoveredMethods++;
                    $coveredMethods++;
                }
            } elseif ($type === 'stmt') {
                $classStmts++;
                $totalStatements++;
                if ($count > 0) {
                    $classCoveredStmts++;
                    $coveredStatements++;
                }
            }
        }

        $classMetrics = $class->getElementsByTagName('metrics')->item(0);
        if ($classMetrics !== null) {
            $classMetrics->setAttribute('methods', (string) $classMethods);
            $classMetrics->setAttribute('coveredmethods', (string) $classCoveredMethods);
            $classMetrics->setAttribute('statements', (string) $classStmts);
            $classMetrics->setAttribute('coveredstatements', (string) $classCoveredStmts);
            $elements = $classMethods + $classStmts;
            $coveredElements = $classCoveredMethods + $classCoveredStmts;
            $classMetrics->setAttribute('elements', (string) $elements);
            $classMetrics->setAttribute('coveredelements', (string) $coveredElements);
        }

        $fm = $file->getElementsByTagName('metrics')->item(0);
        if ($fm !== null) {
            $fileLoc = (int) $fm->getAttribute('loc');
            $fileNcloc = (int) $fm->getAttribute('ncloc');
            $totalLoc += $fileLoc;
            $totalNcloc += $fileNcloc;
            $fm->setAttribute('methods', (string) $classMethods);
            $fm->setAttribute('coveredmethods', (string) $classCoveredMethods);
            $fm->setAttribute('statements', (string) $classStmts);
            $fm->setAttribute('coveredstatements', (string) $classCoveredStmts);
            $fe = $classMethods + $classStmts;
            $fce = $classCoveredMethods + $classCoveredStmts;
            $fm->setAttribute('elements', (string) $fe);
            $fm->setAttribute('coveredelements', (string) $fce);
        }
    }

    $projectMetrics = null;
    foreach ($project->getElementsByTagName('metrics') as $m) {
        if ($m->parentNode === $project) {
            $projectMetrics = $m;
            break;
        }
    }
    if ($projectMetrics !== null) {
        $projectMetrics->setAttribute('files', (string) $totalFiles);
        $projectMetrics->setAttribute('loc', (string) $totalLoc);
        $projectMetrics->setAttribute('ncloc', (string) $totalNcloc);
        $projectMetrics->setAttribute('classes', (string) $totalClasses);
        $projectMetrics->setAttribute('methods', (string) $totalMethods);
        $projectMetrics->setAttribute('coveredmethods', (string) $coveredMethods);
        $projectMetrics->setAttribute('statements', (string) $totalStatements);
        $projectMetrics->setAttribute('coveredstatements', (string) $coveredStatements);
        $totalElements = $totalMethods + $totalStatements;
        $coveredElements = $coveredMethods + $coveredStatements;
        $projectMetrics->setAttribute('elements', (string) $totalElements);
        $projectMetrics->setAttribute('coveredelements', (string) $coveredElements);
    }
}

function printSummary(DOMDocument $doc): void
{
    $project = $doc->getElementsByTagName('project')->item(0);
    if ($project === null) {
        fwrite(STDERR, "Kein project-Element gefunden.\n");
        return;
    }

    $projectMetrics = null;
    foreach ($project->getElementsByTagName('metrics') as $m) {
        if ($m->parentNode === $project) {
            $projectMetrics = $m;
            break;
        }
    }
    if ($projectMetrics === null) {
        fwrite(STDERR, "Keine Projekt-Metriken gefunden.\n");
        return;
    }

    $files = (int) $projectMetrics->getAttribute('files');
    $classes = (int) $projectMetrics->getAttribute('classes');
    $methods = (int) $projectMetrics->getAttribute('methods');
    $coveredMethods = (int) $projectMetrics->getAttribute('coveredmethods');
    $statements = (int) $projectMetrics->getAttribute('statements');
    $coveredStatements = (int) $projectMetrics->getAttribute('coveredstatements');
    $elements = (int) $projectMetrics->getAttribute('elements');
    $coveredElements = (int) $projectMetrics->getAttribute('coveredelements');

    fwrite(STDOUT, "=== Coverage Summary (alle Suiten kombiniert) ===\n");
    fwrite(STDOUT, sprintf("Dateien:    %d\n", $files));
    fwrite(STDOUT, sprintf("Klassen:    %d\n", $classes));
    fwrite(STDOUT, sprintf("Methoden:   %d / %d  (%.1f%%)\n", $coveredMethods, $methods, pct($coveredMethods, $methods)));
    fwrite(STDOUT, sprintf("Zeilen:     %d / %d  (%.1f%%)\n", $coveredStatements, $statements, pct($coveredStatements, $statements)));
    fwrite(STDOUT, sprintf("Elemente:   %d / %d  (%.1f%%)\n", $coveredElements, $elements, pct($coveredElements, $elements)));
}

function pct(int $covered, int $total): float
{
    return $total === 0 ? 0.0 : ($covered / $total) * 100.0;
}
