<?php

declare(strict_types=1);

namespace App\Tests\Stub;

use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\ProtocolVersion;
use Symfony\Component\Mercure\Update;

/**
 * Stub-Implementierung von Mercure HubInterface für Tests.
 * Veröffentlicht nichts — verhindert, dass Tests einen echten Mercure-Hub benötigen.
 */
class NullMercureHub implements HubInterface
{
    public function publish(Update $update): string
    {
        return '';
    }

    public function getPublicUrl(): string
    {
        return 'http://localhost/.well-known/mercure';
    }

    public function getFactory(): ?TokenFactoryInterface
    {
        return null;
    }

    public function getProtocolVersion(): ProtocolVersion
    {
        return ProtocolVersion::V7;
    }

    public function getCookieName(): string
    {
        return 'mercureAuthorization';
    }
}
