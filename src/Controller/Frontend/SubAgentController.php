<?php

namespace App\Controller\Frontend;

use App\Entity\User;
use App\Repository\SubAgentRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class SubAgentController extends AbstractController
{
    #[Route('/subagents', name: 'app_subagents')]
    public function index(SubAgentRepository $subAgentRepository): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Authentifizierung erforderlich.');
        }

        $subAgents = $subAgentRepository->findByUser($user->getId());

        return $this->render('subagents/index.html.twig', [
            'subAgents' => $subAgents
        ]);
    }
}
