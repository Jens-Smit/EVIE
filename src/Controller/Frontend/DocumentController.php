<?php

namespace App\Controller\Frontend;

use App\Repository\DocumentRepository;
use App\Repository\UserProfileRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class DocumentController extends AbstractController
{
    #[Route('/documents', name: 'app_documents')]
    public function index(DocumentRepository $documentRepository, UserProfileRepository $userRepository): Response
    {
        $user = $this->getUser();
        if (!$user) {
            // Default-User laden
            $user = $userRepository->find(1); // oder eine andere ID
        }

        $documents = [];
        if ($user) {
            $documents = $documentRepository->findByUser($user->getId());
        }

        return $this->render('documents/index.html.twig', [
            'documents' => $documents
        ]);
    }
}
