<?php

declare(strict_types=1);

namespace App\AI\Onboarding\Exception;

/**
 * Wird geworfen, wenn ein API-Key waehrend des Onboardings als ungueltig
 * erkannt wird (HTTP 401/403 vom Anbieter). Der OnboardingFlowManager
 * blockiert damit den Wechsel zum naechsten Schritt, sodass der Nutzer
 * den Key korrigieren kann.
 */
final class InvalidApiKeyException extends \RuntimeException
{
}
