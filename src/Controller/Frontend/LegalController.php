<?php

declare(strict_types=1);

namespace App\Controller\Frontend;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * LegalController – rechtliche Seiten (Datenschutzerklärung, Impressum).
 *
 * Frontend-Audit F10 (Datenschutzerklärung) und F11 (Impressum).
 *
 * Diese Seiten sind öffentlich zugänglich (PUBLIC_ACCESS), damit auch nicht
 * angemeldete Besucher die rechtlichen Pflichtangaben einsehen können.
 */
class LegalController extends AbstractController
{
    #[Route('/datenschutz', name: 'app_privacy_policy', methods: ['GET'])]
    public function privacyPolicy(): Response
    {
        return $this->render('legal/datenschutz.html.twig');
    }

    #[Route('/impressum', name: 'app_imprint', methods: ['GET'])]
    public function imprint(): Response
    {
        return $this->render('legal/impressum.html.twig');
    }
}
