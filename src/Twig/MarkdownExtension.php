<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Abhaengigkeitsfreier Markdown-Renderer fuer Dokument- und Agent-Inhalte.
 *
 * Hintergrund (Log-Fall visiongastro): Gespeicherte Dokumente und
 * Agent-Antworten sind Markdown; das Frontend zeigte die Rohzeichenkette,
 * sodass Ueberschriften und Tabellen als Source-Text erschienen. Diese
 * Extension rendert Markdown als formatiertes HTML.
 *
 * Sicherheitsmodell: Der Inhalt wird ZUERST komplett HTML-escaped und erst
 * danach in Markdown-Struktur umgewandelt. Es gibt keinen Raw-HTML-
 * Durchlass, damit Modell-Output kein XSS injizieren kann (kein |raw
 * auf unverarbeitetem LLM-Output noetig).
 *
 * Unterstuetzt: Ueberschriften (#..###), Absaetze, fett/kursiv,
 * Inline-Code, Code-Bloecke, ungeordnete/numerierte Listen,
 * Tabellen (GFM) und Links (mit rel/noopener und https/http-Schema).
 */
final class MarkdownExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('md_to_html', $this->render(...), ['is_safe' => ['html']]),
        ];
    }

    public function render(string $markdown): string
    {
        $escaped = htmlspecialchars($markdown, ENT_QUOTES, 'UTF-8');
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $escaped));
        $html = [];
        $paragraph = [];
        $listType = null;
        $listItems = [];
        $inCodeBlock = false;
        $codeLines = [];
        $tableRows = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($inCodeBlock) {
                if (str_starts_with($trimmed, '```')) {
                    $html[] = '<pre class="bg-slate-100 dark:bg-slate-800 rounded-lg p-3 my-3 overflow-x-auto text-sm"><code>'
                        . implode("\n", $codeLines)
                        . '</code></pre>';
                    $codeLines = [];
                    $inCodeBlock = false;
                } else {
                    $codeLines[] = $line;
                }
                continue;
            }

            if ($trimmed === '') {
                $this->flushParagraph($paragraph, $html);
                $this->flushList($listType, $listItems, $html);
                $this->flushTable($tableRows, $html);
                continue;
            }

            if (str_starts_with($trimmed, '```')) {
                $this->flushParagraph($paragraph, $html);
                $this->flushList($listType, $listItems, $html);
                $this->flushTable($tableRows, $html);
                $inCodeBlock = true;
                continue;
            }

            if (preg_match('/^(#{1,6})\s+(.*)$/', $trimmed, $m) === 1) {
                $this->flushParagraph($paragraph, $html);
                $this->flushList($listType, $listItems, $html);
                $this->flushTable($tableRows, $html);
                $level = min(4, max(2, strlen($m[1]) + 1));
                $classes = [
                    2 => 'text-xl font-bold mt-5 mb-2',
                    3 => 'text-lg font-semibold mt-4 mb-2',
                    4 => 'text-base font-semibold mt-3 mb-1',
                ];
                $html[] = sprintf(
                    '<h%d class="%s">%s</h%d>',
                    $level,
                    $classes[$level],
                    $this->inline($m[2]),
                    $level
                );
                continue;
            }

            if (preg_match('/^\|(.+)\|$/', $trimmed, $m) === 1) {
                $this->flushParagraph($paragraph, $html);
                $this->flushList($listType, $listItems, $html);
                if (preg_match('/^[\|:\-\s]+$/', $trimmed) === 1) {
                    continue;
                }
                $cells = array_map(trim(...), explode('|', $m[1]));
                $tableRows[] = array_map($this->inline(...), $cells);
                continue;
            }
            $this->flushTable($tableRows, $html);

            if (preg_match('/^[-*+]\s+(.*)$/', $trimmed, $m) === 1) {
                $this->flushParagraph($paragraph, $html);
                if ($listType !== 'ul') {
                    $this->flushList($listType, $listItems, $html);
                    $listType = 'ul';
                }
                $listItems[] = '<li class="ml-4 list-disc">' . $this->inline($m[1]) . '</li>';
                continue;
            }
            if (preg_match('/^\d+[.)]\s+(.*)$/', $trimmed, $m) === 1) {
                $this->flushParagraph($paragraph, $html);
                if ($listType !== 'ol') {
                    $this->flushList($listType, $listItems, $html);
                    $listType = 'ol';
                }
                $listItems[] = '<li class="ml-4 list-decimal">' . $this->inline($m[1]) . '</li>';
                continue;
            }
            $this->flushList($listType, $listItems, $html);

            $paragraph[] = $this->inline($trimmed);
        }

        if ($inCodeBlock && $codeLines !== []) {
            $html[] = '<pre class="bg-slate-100 dark:bg-slate-800 rounded-lg p-3 my-3 overflow-x-auto text-sm"><code>'
                . implode("\n", $codeLines)
                . '</code></pre>';
        }
        $this->flushParagraph($paragraph, $html);
        $this->flushList($listType, $listItems, $html);
        $this->flushTable($tableRows, $html);

        return implode("\n", $html);
    }

    /**
     * @param list<string> $paragraph
     * @param list<string> $html
     */
    private function flushParagraph(array &$paragraph, array &$html): void
    {
        if ($paragraph !== []) {
            $html[] = '<p>' . implode('<br>', $paragraph) . '</p>';
            $paragraph = [];
        }
    }

    /**
     * @param list<string>|null $listType
     * @param list<string> $listItems
     * @param list<string> $html
     */
    private function flushList(?string &$listType, array &$listItems, array &$html): void
    {
        if ($listType !== null) {
            $html[] = sprintf('<%1$s>%2$s</%1$s>', $listType, implode('', $listItems));
            $listType = null;
            $listItems = [];
        }
    }

    /**
     * @param list<list<string>> $tableRows
     * @param list<string> $html
     */
    private function flushTable(array &$tableRows, array &$html): void
    {
        if ($tableRows === []) {
            return;
        }
        if (count($tableRows) < 2) {
            $html[] = '<p>' . implode(' | ', $tableRows[0]) . '</p>';
            $tableRows = [];
            return;
        }
        $header = $tableRows[0];
        $body = array_slice($tableRows, 2);
        $thead = '<thead><tr>'
            . implode('', array_map(static fn (string $c): string => '<th class="px-3 py-2 text-left">' . $c . '</th>', $header))
            . '</tr></thead>';
        $tbody = '<tbody>';
        foreach ($body as $row) {
            $tbody .= '<tr class="border-t border-border">'
                . implode('', array_map(static fn (string $c): string => '<td class="px-3 py-2">' . $c . '</td>', $row))
                . '</tr>';
        }
        $tbody .= '</tbody>';
        $html[] = '<div class="overflow-x-auto my-3"><table class="w-full text-sm">' . $thead . $tbody . '</table></div>';
        $tableRows = [];
    }

    /**
     * Inline-Formatierung auf bereits escapedem Text: fett, kursiv,
     * Inline-Code und Links (nur http/https, nofollow noopener).
     */
    private function inline(string $text): string
    {
        $text = preg_replace('/`([^`]+)`/', '<code class="bg-slate-100 dark:bg-slate-800 px-1 rounded text-sm">$1</code>', $text) ?? $text;
        $text = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text) ?? $text;
        $text = preg_replace('/(^|[\s(])\*([^*\s][^*]*)\*/', '$1<em>$2</em>', $text) ?? $text;
        $text = preg_replace(
            '/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/',
            '<a href="$2" class="text-primary underline" rel="nofollow noopener" target="_blank">$1</a>',
            $text
        ) ?? $text;
        return $text;
    }
}
