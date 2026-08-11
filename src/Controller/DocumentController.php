<?php

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
use Symfony\Component\Security\Core\User\UserInterface;

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
        $user = $this->getUser();
        if (!$user) {
            // Default-User laden
            $user = $this->userRepository->find(1); // oder eine andere ID
        }

        $documents = $this->documentRepository->findByUser($user->getId());

        return $this->json($documents, Response::HTTP_OK, [], [
            'groups' => ['document:read']
        ]);
    }

    #[Route('/upload', name: 'upload', methods: ['POST'])]
    public function upload(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            // Default-User laden
            $user = $this->userRepository->find(1); // oder eine andere ID
        }

        $file = $request->files->get('file');
        if (!$file) {
            return $this->json(['error' => 'No file uploaded'], Response::HTTP_BAD_REQUEST);
        }

        $document = new Document();
        $document->setName($file->getClientOriginalName());
        $document->setContent(file_get_contents($file->getPathname()));
        $document->setUser($user);

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
        $user = $this->getUser();
        if (!$user) {
            // Default-User laden
            $user = $this->userRepository->find(1); // oder eine andere ID
        }

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
        $user = $this->getUser();
        if (!$user) {
            // Default-User laden
            $user = $this->userRepository->find(1); // oder eine andere ID
        }

        $this->entityManager->remove($document);
        $this->entityManager->flush();

        return $this->json(['success' => true], Response::HTTP_NO_CONTENT);
    }
}
