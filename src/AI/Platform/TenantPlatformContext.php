<?php

declare(strict_types=1);

namespace App\AI\Platform;

/**
 * Hält den aktuellen Tenant-Identifier (userIdentifier) fuer den laufenden
 * Request-/Pipeline-Aufruf. Symfony-AI-Platform-Services sind Singletons; der
 * TenantAwarePlatform-Decorator liest diesen Kontext, um pro Tenant den
 * korrekten API-Key aus dem SecretService aufzuloesen.
 *
 * Der Kontext ist bewusst ein separater request-scoped Service, damit die
 * PlatformDecorator nicht den Tenant als Methodenargument durch alle
 * PlatformInterface::invoke()-Aufrufe schleifen muss (was die Symfony-AI-
 * Platform-Signatur brechen wuerde). Analog zu QuotaDecorator::setUser().
 */
final class TenantPlatformContext
{
    private ?string $userIdentifier = null;

    public function setUserIdentifier(string $userIdentifier): void
    {
        $this->userIdentifier = $userIdentifier;
    }

    public function getUserIdentifier(): ?string
    {
        return $this->userIdentifier;
    }

    public function clear(): void
    {
        $this->userIdentifier = null;
    }
}
