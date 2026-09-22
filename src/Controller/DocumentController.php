<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Document;
use App\Entity\UserProfile;
use App\Repository\DocumentRepository;
use App\Repository\UserProfileRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/documents', name: 'api_documents_')]
class DocumentController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private DocumentRepository $documentRepository,
        private UserProfileRepository $userRepository
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $userProfile = $this->resolveUserProfile();

        $documents = $this->documentRepository->findByUser($userProfile->getId());

        return $this->json($documents, Response::HTTP_OK, [], [
            'groups' => ['document:read']
        ]);
    }

    #[Route('/upload', name: 'upload', methods: ['POST'])]
    public function upload(Request $request): JsonResponse
    {
        $userProfile = $this->resolveUserProfile();

        $file = $request->files->get('file');
        if (!$file) {
            return $this->json(['error' => 'No file uploaded'], Response::HTTP_BAD_REQUEST);
        }

        $document = new Document();
        $document->setName($file->getClientOriginalName());
        $document->setContent((string) file_get_contents($file->getPathname()));
        $document->setUser($userProfile);

        $this->entityManager->persist($document);
        $this->entityManager->flush();

        return $this->json([
            'success' => true,
            'document' => [
                'id' => $document->getId(),
                'name' => $document->getName(),
                'createdAt' => $document->getCreatedAt()->format('Y-m-d H:i:s')
            ]
        ], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'get', methods: ['GET'])]
    public function get(Document $document): JsonResponse
    {
        $this->resolveUserProfile();

        return $this->json([
            'id' => $document->getId(),
            'name' => $document->getName(),
            'content' => $document->getContent(),
            'createdAt' => $document->getCreatedAt()->format('Y-m-d H:i:s')
        ], Response::HTTP_OK, [], [
            'groups' => ['document:read']
        ]);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    public function delete(Document $document): JsonResponse
    {
        $this->resolveUserProfile();

        $this->entityManager->remove($document);
        $this->entityManager->flush();

        return $this->json(['success' => true], Response::HTTP_NO_CONTENT);
    }

    private function resolveUserProfile(): UserProfile
    {
        $user = $this->getUser();
        $userIdentifier = $user?->getUserIdentifier();

        $userProfile = $userIdentifier !== null
            ? $this->userRepository->findOneBy(['userIdentifier' => $userIdentifier])
            : null;

        if ($userProfile !== null) {
            return $userProfile;
        }

        $userProfile = new UserProfile();
        $userProfile->setUserIdentifier($userIdentifier ?? 'anonymous');
        $userProfile->setName($userIdentifier ?? 'Anonymous');
        $this->entityManager->persist($userProfile);
        $this->entityManager->flush();

        return $userProfile;
    }
}
