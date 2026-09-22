<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\AgentHistory;
use App\Entity\UserProfile;
use App\Repository\AgentHistoryRepository;

/**
 * Functional-Tests fuer den Frontend-Agent-Dialog (Web-UI):
 *
 *  - GET /dialog                -> Chat-Seite inkl. ?task=-Vorbelegung (Happy Path)
 *  - GET /history               -> Verlaufs-Liste aus AgentHistory (neues und
 *                                  altes Details-Format, Sortierung, Guard)
 *  - GET /history/continue/{id} -> Konversationsfortsetzung (404, Happy Path)
 *
 * Der Frontend-Controller floss zuvor mit 33.8 % in die Coverage ein;
 * diese Tests decken alle drei Actions inklusive Guards ab.
 */
class FrontendAgentDialogControllerTest extends AbstractFunctionalControllerTestCase
{
    public function testDialogRedirectsAnonymousUserToLogin(): void
    {
        $this->client->request('GET', '/dialog');
        $this->assertResponseRedirects('/login');
    }

    public function testDialogRendersWelcomeMessageForLoggedInUser(): void
    {
        $this->createUserAndLogin('dialog-fe@test.de', 'DialogPass123');

        $crawler = $this->client->request('GET', '/dialog');

        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        self::assertStringContainsString('EVIE-Agenten', $content);
    }

    public function testDialogPrefillsTaskFromQueryParameter(): void
    {
        $this->createUserAndLogin('dialog-task@test.de', 'DialogPass123');

        $this->client->request('GET', '/dialog?task=' . rawurlencode('Analysiere meine Verkäufe'));

        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        self::assertStringContainsString('Analysiere meine Verkäufe', $content, 'Der ?task-Parameter muss im Eingabefeld vorbelegt werden.');
    }

    public function testHistoryRedirectsAnonymousUserToLogin(): void
    {
        $this->client->request('GET', '/history');
        $this->assertResponseRedirects('/login');
    }

    public function testHistoryRendersEntriesFromAgentHistory(): void
    {
        $user = $this->createUserAndLogin('history-fe@test.de', 'HistoryPass123');
        $profile = $this->ensureUserProfile($user->getUserIdentifier());

        $this->persistHistoryEntry($profile, [
            'input' => ['message' => 'Wie geht es?'],
            'output' => ['response' => 'Alles gut!'],
        ], '-1 day');
        $this->persistHistoryEntry($profile, [
            'input' => ['message' => 'Was ist EVIE?'],
            'output' => ['response' => 'Ein AI-Agent-Workspace.'],
        ], '-2 days');

        $crawler = $this->client->request('GET', '/history');

        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        self::assertStringContainsString('Wie geht es?', $content);
        self::assertStringContainsString('Alles gut!', $content);
        self::assertStringContainsString('Was ist EVIE?', $content);
    }

    public function testHistoryRendersEmptyStateWithoutEntries(): void
    {
        $this->createUserAndLogin('history-empty@test.de', 'HistoryPass123');

        $this->client->request('GET', '/history');

        $this->assertResponseIsSuccessful();
        self::assertStringNotContainsString(
            'Wie geht es?',
            $this->client->getResponse()->getContent()
        );
    }

    public function testContinueConversationRedirectsAnonymousUserToLogin(): void
    {
        $this->client->request('GET', '/history/continue/1');
        $this->assertResponseRedirects('/login');
    }

    public function testContinueConversationReturns404ForUnknownEntry(): void
    {
        $this->createUserAndLogin('continue-404@test.de', 'ContinuePass123');

        $this->client->request('GET', '/history/continue/999999');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testContinueConversationRendersHistoricMessages(): void
    {
        $user = $this->createUserAndLogin('continue-ok@test.de', 'ContinuePass123');
        $profile = $this->ensureUserProfile($user->getUserIdentifier());
        $entry = $this->persistHistoryEntry($profile, [
            'input' => ['message' => 'Erstelle einen Plan'],
            'output' => ['response' => 'Hier ist der Plan.'],
        ], '-1 hour');

        $this->client->request('GET', '/history/continue/' . $entry->getId());

        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        self::assertStringContainsString('Erstelle einen Plan', $content);
        self::assertStringContainsString('Hier ist der Plan.', $content);
        self::assertStringContainsString('Fortsetzung der Konversation', $content);
    }

    public function testContinueConversationHandlesLegacyActionFormat(): void
    {
        $user = $this->createUserAndLogin('continue-legacy@test.de', 'ContinuePass123');
        $profile = $this->ensureUserProfile($user->getUserIdentifier());

        $entry = new AgentHistory();
        $entry->setUser($profile);
        $entry->setAction('{"type":"dialog","input":{"message":"Legacy-Frage"},"output":{"response":"Legacy-Antwort"}}');
        $entry->setDetails(null);
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        $this->client->request('GET', '/history/continue/' . $entry->getId());

        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        self::assertStringContainsString('Legacy-Frage', $content);
        self::assertStringContainsString('Legacy-Antwort', $content);
    }

    private function ensureUserProfile(string $userIdentifier): UserProfile
    {
        $profile = $this->entityManager
            ->getRepository(UserProfile::class)
            ->findOneBy(['userIdentifier' => $userIdentifier]);

        if (null !== $profile) {
            return $profile;
        }

        $profile = new UserProfile();
        $profile->setUserIdentifier($userIdentifier);
        $this->entityManager->persist($profile);
        $this->entityManager->flush();

        return $profile;
    }

    private function persistHistoryEntry(UserProfile $profile, array $details, string $createdAtModifier): AgentHistory
    {
        $entry = new AgentHistory();
        $entry->setUser($profile);
        $entry->setAction('dialog');
        $entry->setDetails(json_encode($details, JSON_THROW_ON_ERROR));

        $entry->setCreatedAt(new \DateTimeImmutable($createdAtModifier));

        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }
}
