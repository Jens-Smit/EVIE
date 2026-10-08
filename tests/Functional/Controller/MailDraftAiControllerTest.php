<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\MailDraft;
use App\Entity\UserProfile;

/**
 * Functional-Tests fuer die KI-Endpunkte des HitlMailControllers:
 *
 *  - POST /tools/mail-drafts/reply: KI-Antwort-Entwurf aus einer gelesenen
 *    E-Mail (HITL: Entwurf landet pending_approval, kein Versand).
 *  - POST /tools/mail-drafts/{id}/improve: Korrektur eines ausstehenden
 *    Entwurfs auf Ausdruck, Rechtschreibung und Tonart.
 *
 * Im Test-Env schlaegt der communication_manager-LLM-Abruf fehl (kein
 * echter API-Key); der dokumentierte Fallback greift: generateReply()
 * erzeugt den Entwurf aus der Ausgangs-E-Mail (source=fallback), improve
 * meldet improved=false und laesst den Entwurf unveraendert. Es werden
 * keine Objekte erfunden, alles wird gegen die echte DB verifiziert.
 */
class MailDraftAiControllerTest extends AbstractFunctionalControllerTestCase
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

    public function testAiReplyRequiresAuthentication(): void
    {
        $this->client->request('POST', '/tools/mail-drafts/reply');
        self::assertResponseRedirects('/login');
    }

    public function testAiReplyCreatesPendingDraftFromReadEmail(): void
    {
        $user = $this->createUserAndLogin('ai-reply@test.de', 'AiReplyPass123');
        $this->createProfile($user->getUserIdentifier());
        $this->client->request('POST', '/tools/mail-drafts/reply', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'subject' => 'Anfrage Angebot',
            'body' => 'Guten Tag, bitte senden Sie mir Ihr Angebot zu.',
            'from' => 'kunde@example.com',
        ]));

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('success', $data['status']);
        self::assertNotEmpty($data['reply']['draft_id']);
        self::assertSame(['kunde@example.com'], $data['reply']['recipients']);

        $draft = $this->entityManager->getRepository(MailDraft::class)
            ->find($data['reply']['draft_id']);
        self::assertNotNull($draft);
        self::assertSame($user->getUserIdentifier(), $draft->getUserIdentifier());
        self::assertSame(MailDraft::STATUS_PENDING, $draft->getStatus());
        self::assertSame('kunde@example.com', $draft->getRecipients()[0]);
    }

    public function testAiReplyRejectsMissingFields(): void
    {
        $this->createUserAndLogin('ai-reply-400@test.de', 'AiReplyPass123');
        $this->client->request('POST', '/tools/mail-drafts/reply', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['subject' => 'Nur Betreff']));

        self::assertResponseStatusCodeSame(400);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('error', $data['status']);
    }

    public function testImproveKeepsPendingDraftWhenLlmUnavailable(): void
    {
        $user = $this->createUserAndLogin('ai-improve@test.de', 'AiImprovePass123');
        $draft = $this->createDraft($user->getUserIdentifier());
        $originalBody = $draft->getBody();

        $this->client->request('POST', '/tools/mail-drafts/' . $draft->getId() . '/improve');
        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('success', $data['status']);
        self::assertFalse($data['draft']['improved']);
        self::assertSame($originalBody, $data['draft']['body']);

        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(MailDraft::class)->find($draft->getId());
        self::assertNotNull($reloaded);
        self::assertSame(MailDraft::STATUS_PENDING, $reloaded->getStatus());
        self::assertSame($originalBody, $reloaded->getBody());
    }

    public function testImproveDeniesForeignDraft(): void
    {
        $this->createDraft('foreign-ai@test.de');
        $this->createUserAndLogin('ai-improve-foreign@test.de', 'AiImprovePass123');
        $draftId = $this->getFirstDraftId();
        $this->client->request('POST', '/tools/mail-drafts/' . $draftId . '/improve');
        self::assertResponseStatusCodeSame(403);
    }

    public function testImproveRejectsNonPendingDraft(): void
    {
        $user = $this->createUserAndLogin('ai-improve-sent@test.de', 'AiImprovePass123');
        $draft = $this->createDraft($user->getUserIdentifier(), MailDraft::STATUS_SENT);
        $this->client->request('POST', '/tools/mail-drafts/' . $draft->getId() . '/improve');
        self::assertResponseStatusCodeSame(400);
    }

    public function testImproveReturns404ForUnknownDraft(): void
    {
        $this->createUserAndLogin('ai-improve-404@test.de', 'AiImprovePass123');
        $this->client->request('POST', '/tools/mail-drafts/999999/improve');
        self::assertResponseStatusCodeSame(404);
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
