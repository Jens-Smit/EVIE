<?php

namespace App\Controller\Frontend;

use App\Entity\User;
use App\Entity\UserProfile;
use App\Repository\DocumentRepository;
use App\Repository\UserProfileRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DocumentController extends AbstractController
{
    public function __construct(
        private readonly UserProfileRepository $userProfileRepository,
    ) {
    }

    #[Route('/documents', name: 'app_documents')]
    public function index(DocumentRepository $documentRepository): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Authentifizierung erforderlich.');
        }

        // Documents haengen am UserProfile (JoinColumn d.user), nicht an der
        // Security-User-Entity. Das Profil wird daher ueber den userIdentifier
        // aufgeloest; ohne Profil rendert die Seite eine leere Liste statt
        // wegen einer fremden Entity-ID nichts anzuzeigen.
        $userProfile = $this->userProfileRepository->findOneBy([
            'userIdentifier' => $user->getUserIdentifier(),
        ]);

        $documents = $userProfile instanceof UserProfile
            ? $documentRepository->findByUser($userProfile->getId())
            : [];

        return $this->render('documents/index.html.twig', [
            'documents' => $documents,
        ]);
    }

    #[Route('/documents/{id}', name: 'app_document_show', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function show(int $id, DocumentRepository $documentRepository): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Authentifizierung erforderlich.');
        }

        $userProfile = $this->userProfileRepository->findOneBy([
            'userIdentifier' => $user->getUserIdentifier(),
        ]);
        if (!$userProfile instanceof UserProfile) {
            throw $this->createAccessDeniedException('Kein Benutzerprofil vorhanden.');
        }

        $document = null;
        foreach ($documentRepository->findByUser($userProfile->getId()) as $candidate) {
            if ($candidate->getId() === $id) {
                $document = $candidate;
                break;
            }
        }
        if ($document === null) {
            throw $this->createNotFoundException(sprintf('Dokument %d nicht gefunden.', $id));
        }

        return $this->render('documents/show.html.twig', [
            'document' => $document,
        ]);
    }
}
