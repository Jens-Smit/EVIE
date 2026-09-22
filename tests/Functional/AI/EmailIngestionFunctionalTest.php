<?php

declare(strict_types=1);

namespace App\Tests\Functional\AI;

use App\AI\Workflow\EmailIngestionService;
use App\Entity\AgentHistory;
use App\Entity\Document;
use App\Entity\MailDraft;
use App\Entity\UserProfile;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Functional-Test fuer EmailIngestionService (Inbound-Postfach-Verarbeitung).
 *
 * EmailIngestionService war laut Coverage-Report ungetestet (0%). Im
 * Test-Env schlaegt der document_processor-LLM-Abruf fehl (kein echter
 * API-Key) und der dokumentierte heuristische Fallback greift - es werden
 * keine Objekte erfunden, alles wird gegen die echte DB verifiziert.
 */
final class EmailIngestionFunctionalTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private EmailIngestionService $ingestionService;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->ingestionService = static::getContainer()->get(EmailIngestionService::class);

        $schemaTool = new SchemaTool($this->entityManager);
        try {
            $schemaTool->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());
        } catch (\Throwable) {
        }
    }

    protected function tearDown(): void
    {
        try {
            foreach ([MailDraft::class, Document::class, AgentHistory::class, UserProfile::class] as $class) {
                $this->entityManager->createQueryBuilder()
                    ->delete($class, 'e')
                    ->getQuery()->execute();
            }
            $this->entityManager->clear();
        } catch (\Throwable) {
        }
        parent::tearDown();
        static::ensureKernelShutdown();
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

    public function testIngestEmailThrowsForUnknownProfile(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->ingestionService->ingestEmail('unknown@test.de', [
            'subject' => 'Test',
            'body' => 'Hallo',
        ]);
    }

    public function testIngestEmailWithoutAttachmentsOnlyLogsHistory(): void
    {
        $identifier = 'ingest-noattach@test.de';
        $this->createProfile($identifier);

        $result = $this->ingestionService->ingestEmail($identifier, [
            'subject' => 'Anfrage ohne Anhang',
            'body' => 'Nur Text',
            'from' => 'kunde@example.com',
        ]);

        self::assertSame(0, $result['processed_attachments']);
        self::assertSame([], $result['documents']);
        self::assertNull($result['reply_draft_id']);

        $history = $this->entityManager->createQueryBuilder()
            ->select('h.action')
            ->from(AgentHistory::class, 'h')
            ->where('h.action = :action')
            ->setParameter('action', 'email_ingested')
            ->getQuery()
            ->getSingleColumnResult();
        self::assertContains('email_ingested', $history);
    }

    public function testIngestEmailWithAttachmentCreatesDocumentAndDraft(): void
    {
        $identifier = 'ingest-attach@test.de';
        $this->createProfile($identifier);

        $result = $this->ingestionService->ingestEmail($identifier, [
            'subject' => 'Rechnung 2024',
            'body' => 'Im Anhang finden Sie unsere Rechnung.',
            'from' => 'kunde@example.com',
            'attachments' => [
                [
                    'filename' => 'rechnung_2024.txt',
                    'content' => 'Rechnung ueber 1.234,56 EUR, faellig am 2024-12-31.',
                ],
                [
                    'filename' => '',
                    'content' => 'ungueltig, wird uebersprungen',
                ],
            ],
        ]);

        self::assertSame(1, $result['processed_attachments']);
        self::assertCount(1, $result['documents']);
        self::assertNotNull($result['reply_draft_id']);

        $document = $result['documents'][0];
        self::assertSame('rechnung_2024.txt', $document['filename']);
        self::assertSame('Rechnung', $document['type']);
        self::assertNotSame('', $document['recommendation']);

        $draft = $this->entityManager->find(MailDraft::class, $result['reply_draft_id']);
        self::assertNotNull($draft);
        self::assertSame(MailDraft::STATUS_PENDING, $draft->getStatus());
        self::assertSame($identifier, $draft->getUserIdentifier());
        self::assertStringContainsString('Rechnung 2024', $draft->getSubject());

        $documentEntity = $this->entityManager->find(Document::class, $document['document_id']);
        self::assertNotNull($documentEntity);
        self::assertSame('rechnung_2024.txt', $documentEntity->getName());
    }

    public function testIngestEmailAnalysisRecordsHistory(): void
    {
        $identifier = 'ingest-history@test.de';
        $this->createProfile($identifier);

        $this->ingestionService->ingestEmail($identifier, [
            'subject' => 'Vertrag',
            'body' => 'Vertragsentwurf',
            'from' => 'kunde@example.com',
            'attachments' => [
                ['filename' => 'vertrag.pdf', 'content' => 'Vertrag mit Laufzeit bis 2025-06-30.'],
            ],
        ]);

        $actions = $this->entityManager->createQueryBuilder()
            ->select('h.action')
            ->from(AgentHistory::class, 'h')
            ->getQuery()
            ->getSingleColumnResult();

        self::assertContains('document_analysis', $actions);
        self::assertContains('email_ingested', $actions);
    }
}
