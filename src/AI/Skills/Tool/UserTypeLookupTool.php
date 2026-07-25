<?php

namespace App\AI\Skills\Tool;

use App\Repository\UserProfileRepository;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

#[AsTool('user_type_lookup', 'Liefert den Nutzertyp (Business/Privat) für eine User-ID.')]
final class UserTypeLookupTool
{
    public function __construct(private UserProfileRepository $repo) {}

    public function __invoke(string $userIdentifier): string
    {
        $profile = $this->repo->findOneBy(['userIdentifier' => $userIdentifier]);

        return $profile?->getUserType() ?? 'unknown';
    }
}