<?php

namespace App\AI\Skills\Tool;

use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

#[AsTool('excel_parser', 'Parsen von Excel-Dateien und Extraktion von Daten.')]
final class ExcelParserTool
{
    public function __invoke(string $filePath): string
    {
        // Platzhalter: In einer echten Implementierung würde hier eine Excel-Datei geparsed werden
        return sprintf('Excel-Datei "%s" wurde erfolgreich geparsed. (Platzhalter)', $filePath);
    }
}