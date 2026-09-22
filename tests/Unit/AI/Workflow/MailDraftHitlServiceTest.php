<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Workflow;

use App\AI\Security\AuditLogger;
use App\AI\Workflow\MailDraftHitlService;
use App\Entity\AgentHistory;
use App\Entity\MailDraft;
use App\Entity\UserProfile;
use App\Repository\AgentHistoryRepository;
use App\Repository\MailDraftRepository;
use App\Repository\UserProfileRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\MailerInterface;

/**
 * Unit-Tests fuer MailDraftHitlService (HITL E-Mail-Workflow, Blueprint \u00a75).
 *
 * MailDraftHitlService war laut Coverage-Report ungetestet (0%). Deckt
 * prepareDraft/approveDraft/rejectDraft inkl. Validierungs- und
 * Statusuebergangs-Fehlern ab.
 */
final class MailDraftHitlServiceTest extends TestCase
{
    private MailDraftRepository&MockObject $mailDraftRepository;
    private UserProfileRepository&MockObject $userProfileRepository;
    private AgentHistoryRepository&MockObject $historyRepository;
    private MailerInterface&MockObject $mailer;
    private AuditLogger&MockObject $auditLogger;
    private MailDraftHitlService $service;

    protected function setUp(): void
    {
        $this->mailDraftRepository = $this->createMock(MailDraftRepository::class);
        $this->userProfileRepository = $this->createMock(UserProfileRepository::class);
        $this->historyRepository = $this->createMock(AgentHistoryRepository::class);
        $this->mailer = $this->createMock(MailerInterface::class);
        $this->auditLogger = $this->createMock(AuditLogger::class);
        $this->service = new MailDraftHitlService(
            $this->mailDraftRepository,
            $this->userProfileRepository,
            $this->historyRepository,
            $this->mailer,
            $this->auditLogger,
            new NullLogger()
        );
    }

    private function mockProfile(): UserProfile
    {
        $profile = new UserProfile();
        $profile->setUserIdentifier('hitl-unit@test.de');
        $profile->setName('hitl-unit@test.de');
        return $profile;
    }

    public function testPrepareDraftThrowsWithoutSubject(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->prepareDraft('hitl-unit@test.de', '', 'Body', ['to@example.com']);
    }

    public function testPrepareDraftThrowsWithoutRecipients(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->prepareDraft('hitl-unit@test.de', 'Betreff', 'Body', []);
    }

    public function testPrepareDraftThrowsForUnknownProfile(): void
    {
        $this->userProfileRepository->method('findOneBy')->willReturn(null);

        $this->expectException(\InvalidArgumentException::class);

        $this->service->prepareDraft('unknown@test.de', 'Betreff', 'Body', ['to@example.com']);
    }

    public function testPrepareDraftPersistsPendingDraft(): void
    {
        $profile = $this->mockProfile();
        $this->userProfileRepository->method('findOneBy')->willReturn($profile);
        $this->mailDraftRepository
            ->expects(self::once())
            ->method('save');

        $draft = $this->service->prepareDraft(
            'hitl-unit@test.de',
            'Angebot',
            'Sehr geehrte Damen und Herren',
            ['kunde@example.com'],
            'absender@example.com'
        );

        self::assertSame(MailDraft::STATUS_PENDING, $draft->getStatus());
        self::assertSame('Angebot', $draft->getSubject());
        self::assertSame(['kunde@example.com'], $draft->getRecipients());
        self::assertSame('absender@example.com', $draft->getSender());
        self::assertTrue($draft->isPending());
    }

    public function testApproveDraftThrowsForNonPendingDraft(): void
    {
        $draft = new MailDraft();
        $draft->setUserIdentifier('hitl-unit@test.de');
        $draft->setSubject('B');
        $draft->setBody('C');
        $draft->setRecipients(['to@example.com']);
        $draft->setStatus(MailDraft::STATUS_SENT);

        $this->expectException(\LogicException::class);

        $this->service->approveDraft($draft);
    }

    public function testApproveDraftSendsMailAndSetsStatusSent(): void
    {
        $draft = new MailDraft();
        $draft->setUserIdentifier('hitl-unit@test.de');
        $draft->setSubject('Freigabe');
        $draft->setBody('Inhalt');
        $draft->setRecipients(['to@example.com']);
        $draft->setSender('from@example.com');
        $draft->setStatus(MailDraft::STATUS_PENDING);

        $this->mailer->expects(self::once())->method('send');
        $this->mailDraftRepository->method('save');

        $result = $this->service->approveDraft($draft);

        self::assertSame(MailDraft::STATUS_SENT, $result->getStatus());
        self::assertNotNull($result->getSentAt());
        self::assertNotNull($result->getApprovedAt());
    }

    public function testApproveDraftKeepsApprovedOnMailerFailure(): void
    {
        $draft = new MailDraft();
        $draft->setUserIdentifier('hitl-unit@test.de');
        $draft->setSubject('Fehlerfall');
        $draft->setBody('Inhalt');
        $draft->setRecipients(['to@example.com']);
        $draft->setStatus(MailDraft::STATUS_PENDING);

        $this->mailer->method('send')->willThrowException(new \RuntimeException('SMTP down'));

        try {
            $this->service->approveDraft($draft);
            self::fail('Exception erwartet');
        } catch (\RuntimeException) {
            self::assertSame(MailDraft::STATUS_APPROVED, $draft->getStatus());
        }
    }

    public function testRejectDraftThrowsForNonPendingDraft(): void
    {
        $draft = new MailDraft();
        $draft->setUserIdentifier('hitl-unit@test.de');
        $draft->setSubject('B');
        $draft->setBody('C');
        $draft->setRecipients(['to@example.com']);
        $draft->setStatus(MailDraft::STATUS_APPROVED);

        $this->expectException(\LogicException::class);

        $this->service->rejectDraft($draft, 'Grund');
    }

    public function testRejectDraftThrowsWithoutReason(): void
    {
        $draft = new MailDraft();
        $draft->setUserIdentifier('hitl-unit@test.de');
        $draft->setSubject('B');
        $draft->setBody('C');
        $draft->setRecipients(['to@example.com']);
        $draft->setStatus(MailDraft::STATUS_PENDING);

        $this->expectException(\InvalidArgumentException::class);

        $this->service->rejectDraft($draft, '   ');
    }

    public function testRejectDraftSetsStatusAndReason(): void
    {
        $draft = new MailDraft();
        $draft->setUserIdentifier('hitl-unit@test.de');
        $draft->setSubject('Ablehnung');
        $draft->setBody('Inhalt');
        $draft->setRecipients(['to@example.com']);
        $draft->setStatus(MailDraft::STATUS_PENDING);

        $this->mailDraftRepository->method('save');

        $result = $this->service->rejectDraft($draft, 'Nicht relevant');

        self::assertSame(MailDraft::STATUS_REJECTED, $result->getStatus());
        self::assertSame('Nicht relevant', $result->getRejectionReason());
        self::assertFalse($result->isPending());
    }
}
