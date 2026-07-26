<?php
// src/Controller/Frontend/ToolApprovalController.php
namespace App\Controller\Frontend;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class ToolApprovalController extends AbstractController
{
    #[Route('/tools/pending', name: 'frontend_tools_pending', methods: ['GET'])]
    public function pending(): Response
    {
        // Leere Tools-Liste für den Anfang
        $tools = [];

        return $this->render('tools/pending.html.twig', [
            'tools' => $tools,
        ]);
    }
}
