<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\Document;
use App\Entity\UserProfile;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Functional-Tests fuer DocumentController und QuotaController.
 *
 * Beide Controller waren laut Coverage-Report ungetestet (0%). Deckt
 * Document list/upload/get/delete und Quota index/usage/remaining ab.
 */
class DocumentAndQuotaControllerTest extends AbstractFunctionalControllerTest
{
    protected function tearDown(): void
    {
        try {
            $this->entityManager->createQueryBuilder()
                ->delete(Document::class, 'd')
                ->getQuery()->execute();
        } catch (\Throwable) {
        }
        parent::tearDown();
    }

    private function createProfile(string $identifier): UserProfile
    {
        $profile = new UserProfile();
        $profile->setUserIdentifier($identifier);
        $profile->setName($identifier);
        $this->entityManager->persist($profile);
        $this->entityManager->flush();
        return $profile;
    }

    private function createDocument(UserProfile $profile): Document
    {
        $document = new Document();
        $document->setName('test_doc.txt');
        $document->setContent('Dokumenteninhalt');
        $document->setUser($profile);
        $this->entityManager->persist($document);
        $this->entityManager->flush();
        return $document;
    }

    public function testDocumentListReturnsOwnDocuments(): void
    {
        $user = $this->createUserAndLogin('doc-list@test.de', 'DocPass123');
        $profile = $this->createProfile($user->getUserIdentifier());
        $this->createDocument($profile);

        $this->client->request('GET', '/api/documents');

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertGreaterThanOrEqual(1, count($data));
    }

    public function testDocumentUploadWithoutFileReturns400(): void
    {
        $this->createUserAndLogin('doc-upload400@test.de', 'DocPass123');
        $this->client->request('POST', '/api/documents/upload');

        self::assertResponseStatusCodeSame(400);
    }

    public function testDocumentUploadPersistsFile(): void
    {
        $user = $this->createUserAndLogin('doc-upload@test.de', 'DocPass123');
        $tmpFile = tempnam(sys_get_temp_dir(), 'evie_test');
        file_put_contents($tmpFile, 'Upload-Inhalt');
        $uploadedFile = new UploadedFile($tmpFile, 'upload.txt', 'text/plain', null, true);

        $this->client->request('POST', '/api/documents/upload', [], [], ['file' => $uploadedFile]);

        self::assertResponseStatusCodeSame(201);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertTrue($data['success']);
        self::assertSame('upload.txt', $data['document']['name']);
        unlink($tmpFile);
    }

    public function testDocumentGetReturnsContent(): void
    {
        $user = $this->createUserAndLogin('doc-get@test.de', 'DocPass123');
        $profile = $this->createProfile($user->getUserIdentifier());
        $document = $this->createDocument($profile);

        $this->client->request('GET', '/api/documents/' . $document->getId());

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('test_doc.txt', $data['name']);
        self::assertSame('Dokumenteninhalt', $data['content']);
    }

    public function testDocumentGetReturns404ForUnknownId(): void
    {
        $this->createUserAndLogin('doc-404@test.de', 'DocPass123');
        $this->client->request('GET', '/api/documents/999999');

        self::assertResponseStatusCodeSame(404);
    }

    public function testDocumentDeleteRemovesDocument(): void
    {
        $user = $this->createUserAndLogin('doc-delete@test.de', 'DocPass123');
        $profile = $this->createProfile($user->getUserIdentifier());
        $document = $this->createDocument($profile);

        $this->client->request('DELETE', '/api/documents/' . $document->getId());

        self::assertResponseStatusCodeSame(204);
        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(Document::class, $document->getId()));
    }

    public function testQuotaSettingsPageRequiresAuthentication(): void
    {
        $this->client->request('GET', '/settings/quota');

        self::assertResponseRedirects('/login');
    }

    public function testQuotaSettingsPageRenders(): void
    {
        $this->createUserAndLogin('quota-page@test.de', 'QuotaPass123');
        $this->client->request('GET', '/settings/quota');

        self::assertResponseIsSuccessful();
    }

    public function testQuotaUsageReturnsUnauthorizedWithoutLogin(): void
    {
        $this->client->request('GET', '/api/quota/usage');

        self::assertResponseStatusCodeSame(401);
    }

    public function testQuotaUsageReturnsData(): void
    {
        $this->createUserAndLogin('quota-usage@test.de', 'QuotaPass123');
        $this->client->request('GET', '/api/quota/usage');

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
    }

    public function testQuotaRemainingReturnsData(): void
    {
        $this->createUserAndLogin('quota-remaining@test.de', 'QuotaPass123');
        $this->client->request('GET', '/api/quota/remaining');

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('remaining_tokens', $data);
        self::assertArrayHasKey('remaining_requests', $data);
    }
}
