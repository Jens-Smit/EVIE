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
}
