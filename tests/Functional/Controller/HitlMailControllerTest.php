<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\MailDraft;
use App\Entity\UserProfile;

/**
 * Functional-Tests fuer HitlMailController (HITL E-Mail-Freigabe).
 *
 * HitlMailController war laut Coverage-Report ungetestet (0%). Deckt
 * list/approve/reject ab inkl. Ownership-Schutz (403) und 404-Faellen.
 * Der Mailer ist im Test-Env null://null, der Versand ist ein No-Op.
 */
class HitlMailControllerTest extends AbstractFunctionalControllerTestCase
{
    protected function tearDown(): void
    {
        try {
            $this->entityManager->createQueryBuilder()
                ->delete(MailDraft::class, 'm')
                ->getQuery()->execute();
            $this->entityManager->createQueryBuilder()
                ->delete(UserProfile::class, 'p')
                ->getQuery()->execute();
        } catch (\Throwable) {
        }
        parent::tearDown();
    }

    private function createProfile(string $identifier): UserProfile
    {
        $profile = $this->entityManager->getRepository(UserProfile::class)
            ->findOneBy(['userIdentifier' => $identifier]);

        if ($profile !== null) {
            return $profile;
        }

        $profile = new UserProfile();
        $profile->setUserIdentifier($identifier);
        $profile->setName($identifier);
        $this->entityManager->persist($profile);
        $this->entityManager->flush();

        return $profile;
    }

    private function createDraft(string $userIdentifier, string $status = MailDraft::STATUS_PENDING): MailDraft
    {
        $draft = new MailDraft();
        $draft->setUserIdentifier($userIdentifier);
        $draft->setUserProfile($this->createProfile($userIdentifier));
        $draft->setSubject('Test Betreff');
        $draft->setBody('Test-Inhalt');
        $draft->setRecipients(['to@example.com']);
        $draft->setSender('from@example.com');
        $draft->setStatus($status);
        $this->entityManager->persist($draft);
        $this->entityManager->flush();
        return $draft;
    }

    public function testListPendingRequiresAuthentication(): void
    {
        $this->client->request('GET', '/tools/mail-drafts');

        self::assertResponseRedirects('/login');
    }

    public function testListPendingReturnsOwnDrafts(): void
    {
        $user = $this->createUserAndLogin('hitl-list@test.de', 'HitlPass123');
        $this->createDraft($user->getUserIdentifier());
        $this->client->request('GET', '/tools/mail-drafts');

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('success', $data['status']);
        self::assertCount(1, $data['drafts']);
        self::assertSame('Test Betreff', $data['drafts'][0]['subject']);
    }

    public function testApproveSendsPendingDraft(): void
    {
        $user = $this->createUserAndLogin('hitl-approve@test.de', 'HitlPass123');
        $draft = $this->createDraft($user->getUserIdentifier());

        $this->client->request('POST', '/tools/mail-drafts/' . $draft->getId() . '/approve');

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('success', $data['status']);
        self::assertNotNull($data['draft']['sent_at']);
    }

    public function testApproveDeniesForeignDraft(): void
    {
        $this->createDraft('foreign@test.de');
        $this->createUserAndLogin('hitl-foreign@test.de', 'HitlPass123');
        $draftId = $this->getFirstDraftId();

        $this->client->request('POST', '/tools/mail-drafts/' . $draftId . '/approve');

        self::assertResponseStatusCodeSame(403);
    }

    public function testApproveReturns404ForUnknownDraft(): void
    {
        $this->createUserAndLogin('hitl-404@test.de', 'HitlPass123');
        $this->client->request('POST', '/tools/mail-drafts/999999/approve');

        self::assertResponseStatusCodeSame(404);
    }

    public function testRejectMarksDraftRejected(): void
    {
        $user = $this->createUserAndLogin('hitl-reject@test.de', 'HitlPass123');
        $draft = $this->createDraft($user->getUserIdentifier());

        $this->client->request('POST', '/tools/mail-drafts/' . $draft->getId() . '/reject', [
            'reason' => 'Nicht aktuell',
        ]);

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('success', $data['status']);
        self::assertSame(MailDraft::STATUS_REJECTED, $data['draft']['status']);
        self::assertSame('Nicht aktuell', $data['draft']['rejection_reason']);
    }

    public function testRejectWithJsonBody(): void
    {
        $user = $this->createUserAndLogin('hitl-reject-json@test.de', 'HitlPass123');
        $draft = $this->createDraft($user->getUserIdentifier());

        $this->client->request('POST', '/tools/mail-drafts/' . $draft->getId() . '/reject', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['reason' => 'JSON-Reason']));

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('JSON-Reason', $data['draft']['rejection_reason']);
    }

    private function getFirstDraftId(): int
    {
        $draft = $this->entityManager->createQueryBuilder()
            ->select('m.id')
            ->from(MailDraft::class, 'm')
            ->setMaxResults(1)
            ->getQuery()
            ->getSingleResult();

        return (int) $draft['id'];
    }
}
