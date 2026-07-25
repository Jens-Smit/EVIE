<?php

namespace App\AI\Skills\Tool;

use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

#[AsTool('data_analyzer', 'Analysiert Daten und liefert eine Zusammenfassung.')]
final class DataAnalyzerTool
{
    public function __invoke(array $data): string
    {
        // Beispiel-Implementierung: Einfache Datenanalyse
        $summary = [
            'count' => count($data),
            'keys' => array_keys($data[0] ?? []),
        ];

        return json_encode($summary, JSON_PRETTY_PRINT);
    }
}