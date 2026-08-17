<?php

namespace App\Controller\Frontend;

use App\Entity\User;
use App\Repository\DocumentRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class DocumentController extends AbstractController
{
    #[Route('/documents', name: 'app_documents')]
    public function index(DocumentRepository $documentRepository): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Authentifizierung erforderlich.');
        }

        $documents = $documentRepository->findByUser($user->getId());

        return $this->render('documents/index.html.twig', [
            'documents' => $documents
        ]);
    }
}
