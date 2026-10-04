<?php

declare(strict_types=1);

namespace App\AI\Pipeline\Exception;

use RuntimeException;

/**
 * Signalisiert, dass ein Recherche-Schritt (website_researcher) keine
 * belegten Quelldaten liefern konnte oder der Ziel-Abruf fehlgeschlagen
 * ist. Der ExecutionCoordinator wandelt diese Exception in eine
 * Rueckfrage (clarify) an den Nutzer um, statt ein Dokument auf Basis
 * von Modellwissen zu generieren (Blueprint: keine Halluzinationen).
 */
final class UngroundedResearchException extends RuntimeException
{
}
