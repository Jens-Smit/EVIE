<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Skills\Tool;

use App\AI\Skills\Tool\UserTypeLookupTool;
use App\Entity\UserProfile;
use App\Repository\UserProfileRepository;
use PHPUnit\Framework\TestCase;

/**
 * Vollstaendige Test-Abdeckung fuer UserTypeLookupTool.
 */
final class UserTypeLookupToolTest extends TestCase
{
    public function testInvokeReturnsUserTypeWhenProfileFound(): void
    {
        $profile = new UserProfile();
        $profile->setUserType('recruiter');
        $repo = $this->createMock(UserProfileRepository::class);
        $repo->expects(self::once())
            ->method('findOneBy')
            ->with(['userIdentifier' => 'user-1'])
            ->willReturn($profile);

        $tool = new UserTypeLookupTool($repo);
        self::assertSame('recruiter', $tool('user-1'));
    }

    public function testInvokeReturnsUnknownWhenProfileNotFound(): void
    {
        $repo = $this->createMock(UserProfileRepository::class);
        $repo->method('findOneBy')->willReturn(null);

        $tool = new UserTypeLookupTool($repo);
        self::assertSame('unknown', $tool('missing'));
    }

    public function testInvokeReturnsUnknownWhenUserTypeNull(): void
    {
        $profile = new UserProfile();
        $repo = $this->createMock(UserProfileRepository::class);
        $repo->method('findOneBy')->willReturn($profile);

        $tool = new UserTypeLookupTool($repo);
        self::assertSame('unknown', $tool('user-without-type'));
    }
}
