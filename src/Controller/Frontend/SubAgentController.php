<?php

namespace App\Controller\Frontend;

use App\Repository\SubAgentRepository;
use App\Repository\UserProfileRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class SubAgentController extends AbstractController
{
    #[Route('/subagents', name: 'app_subagents')]
    public function index(SubAgentRepository $subAgentRepository, UserProfileRepository $userRepository): Response
    {
        $user = $this->getUser();
        if (!$user) {
            // Default-User laden
            $user = $userRepository->find(1); // oder eine andere ID
        }

        $subAgents = [];
        if ($user) {
            $subAgents = $subAgentRepository->findByUser($user->getId());
        }

        return $this->render('subagents/index.html.twig', [
            'subAgents' => $subAgents
        ]);
    }
}
