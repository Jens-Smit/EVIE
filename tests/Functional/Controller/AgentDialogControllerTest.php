<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

/**
 * Functional-Tests fuer AgentDialogController.
 *
 * AgentDialogController war laut Coverage-Report weitgehend ungetestet
 * (0%). Deckt die HTTP-Schicht ab: 401 ohne Auth, 400 ohne message-Feld,
 * JSON- und FormData-Parsing sowie die IDOR-Geschuetzte history-Route.
 */
class AgentDialogControllerTest extends AbstractFunctionalControllerTest
{
    public function testDialogRequiresAuthentication(): void
    {
        $this->client->request('POST', '/api/agent/dialog', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['message' => 'Hallo']));

        self::assertResponseStatusCodeSame(401);
    }

    public function testDialogReturns400WithoutMessage(): void
    {
        $this->createUserAndLogin('dialog-400@test.de', 'DialogPass123');
        $this->client->request('POST', '/api/agent/dialog', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['foo' => 'bar']));

        self::assertResponseStatusCodeSame(400);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('error', $data);
    }

    public function testDialogAcceptsFormDataWithoutMessage(): void
    {
        $this->createUserAndLogin('dialog-form@test.de', 'DialogPass123');
        $this->client->request('POST', '/api/agent/dialog', ['prompt' => '']);

        self::assertResponseStatusCodeSame(400);
    }

    public function testHistoryRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/agent/history/someone@test.de');

        self::assertResponseStatusCodeSame(401);
    }

    public function testHistoryDeniesForeignUserIdentifier(): void
    {
        $user = $this->createUserAndLogin('dialog-own@test.de', 'DialogPass123');
        $this->client->request('GET', '/api/agent/history/foreign@test.de');

        self::assertResponseStatusCodeSame(403);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('error', $data);
    }

    public function testHistoryReturnsOwnHistoryAsJsonArray(): void
    {
        $user = $this->createUserAndLogin('dialog-hist@test.de', 'DialogPass123');
        $this->client->request('GET', '/api/agent/history/' . $user->getUserIdentifier());

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
    }
}
