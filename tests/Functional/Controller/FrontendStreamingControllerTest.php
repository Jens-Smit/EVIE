<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\AI\Streaming\StreamingSessionManager;

/**
 * Functional-Tests fuer den Frontend-StreamingController (Web-UI):
 *
 *  - GET /streaming/sessions       -> Liste inkl. aktiver Sessions (Login-Pflicht)
 *  - GET /streaming/sessions/{id}  -> Detail (404, Fremd-Zugriff 403, Happy Path)
 *  - GET /streaming/sessions/new   -> Start-Formular (Login-Pflicht)
 *
 * Deckt den kompletten Happy Path und alle Guard-Zweige des Controllers,
 * der zuvor gar nicht in die Coverage einfloss (0 %).
 */
class FrontendStreamingControllerTest extends AbstractFunctionalControllerTestCase
{
    private StreamingSessionManager $sessionManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sessionManager = static::getContainer()->get(StreamingSessionManager::class);
    }

    public function testListSessionsRedirectsAnonymousUserToLogin(): void
    {
        $this->client->request('GET', '/streaming/sessions');
        $this->assertResponseRedirects('/login');
    }

    public function testListSessionsShowsUsersSessions(): void
    {
        $user = $this->createUserAndLogin('stream-list@test.de', 'StreamPass123');
        $identifier = $user->getUserIdentifier();

        $own = $this->sessionManager->createSession('list_tool', ['x' => 1], $identifier);
        $this->sessionManager->startSession($own->getSessionId());
        $ownSecond = $this->sessionManager->createSession('list_tool_2', ['x' => 2], $identifier);
        $foreign = $this->sessionManager->createSession('other_users_tool', ['x' => 3], 'fremd@example.com');

        $crawler = $this->client->request('GET', '/streaming/sessions');

        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        self::assertStringContainsString($own->getSessionId(), $content, 'Die eigene aktive Session muss erscheinen.');
        self::assertStringContainsString($ownSecond->getSessionId(), $content, 'Die eigene inaktive Session muss erscheinen.');
        self::assertStringContainsString('list_tool', $content);
        self::assertStringNotContainsString($foreign->getSessionId(), $content, 'Fremde Sessions duerfen nicht erscheinen.');
    }

    public function testShowSessionRedirectsAnonymousUserToLogin(): void
    {
        $this->client->request('GET', '/streaming/sessions/irrelevant-id');
        $this->assertResponseRedirects('/login');
    }

    public function testShowSessionReturns404ForUnknownSession(): void
    {
        $this->createUserAndLogin('stream-404@test.de', 'StreamPass123');
        $this->client->request('GET', '/streaming/sessions/does-not-exist');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testShowSessionDeniesAccessToForeignSession(): void
    {
        $owner = $this->createUser('stream-owner@test.de', 'StreamPass123');
        $session = $this->sessionManager->createSession(
            'foreign_tool',
            ['x' => 1],
            $owner->getUserIdentifier()
        );

        $this->createUserAndLogin('stream-intruder@test.de', 'StreamPass123');
        $this->client->request('GET', '/streaming/sessions/' . $session->getSessionId());

        $this->assertResponseStatusCodeSame(403);
    }

    public function testShowSessionHappyPathRendersDetailPage(): void
    {
        $user = $this->createUserAndLogin('stream-show@test.de', 'StreamPass123');
        $session = $this->sessionManager->createSession(
            'show_tool',
            ['input' => 'demo'],
            $user->getUserIdentifier()
        );
        $this->sessionManager->startSession($session->getSessionId());

        $crawler = $this->client->request('GET', '/streaming/sessions/' . $session->getSessionId());

        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        self::assertStringContainsString($session->getSessionId(), $content);
        self::assertStringContainsString('show_tool', $content);
        self::assertSame('running', $crawler->filter('#session-status-value')->text());
    }

    public function testNewSessionRedirectsAnonymousUserToLogin(): void
    {
        $this->client->request('GET', '/streaming/sessions/new');
        $this->assertResponseRedirects('/login');
    }

    public function testNewSessionRendersFormForLoggedInUser(): void
    {
        $this->createUserAndLogin('stream-new@test.de', 'StreamPass123');
        $this->client->request('GET', '/streaming/sessions/new');

        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        self::assertStringContainsString('streaming', strtolower($content));
    }
}
