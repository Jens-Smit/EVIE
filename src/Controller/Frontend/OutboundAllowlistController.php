<?php

declare(strict_types=1);

namespace App\Controller\Frontend;

use App\AI\Security\AuditLogger;
use App\Entity\OutboundAllowlistEntry;
use App\Repository\OutboundAllowlistEntryRepository;
use App\Security\OrganizationContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Frontend-Freigabe fuer ausgehende HTTP-Ziele (Blueprint §4.D/§5:
 * explizite Freigabe, Security by Default).
 *
 * Admins geben hier Hosts frei (z. B. "api.tavily.com"); die
 * OutboundRequestPolicy beruecksichtigt die Eintraegen lazy beim ersten
 * URL-Check. Eine Freigabe hebt keine SSRF-Blockliste auf: interne
 * Zieladressen bleiben blockiert (Defense-in-Depth in der Policy).
 */
final class OutboundAllowlistController extends AbstractController
{
    public function __construct(
        private readonly OutboundAllowlistEntryRepository $repository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly OrganizationContext $organizationContext,
    ) {
    }

    #[Route('/settings/outbound-allowlist', name: 'app_settings_outbound_allowlist', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function index(): Response
    {
        return $this->render('settings/outbound_allowlist.html.twig', [
            'entries' => $this->repository->findBy([], ['hostPattern' => 'ASC']),
        ]);
    }

    #[Route('/settings/outbound-allowlist', name: 'app_settings_outbound_allowlist_create', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function create(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('outbound_allowlist_create', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültiges CSRF-Token.');

            return $this->redirectToRoute('app_settings_outbound_allowlist');
        }

        $hostPattern = strtolower(trim((string) $request->request->get('hostPattern')));
        $patternType = (string) $request->request->get('patternType', OutboundAllowlistEntry::TYPE_SUFFIX);
        $description = trim((string) $request->request->get('description', ''));

        if (!preg_match('/^[a-z0-9.*_-]+$/', $hostPattern)) {
            $this->addFlash('error', 'Ungültiges Host-Pattern (erlaubt: a-z, 0-9, Punkt, Bindestrich, Unterstrich, *).');

            return $this->redirectToRoute('app_settings_outbound_allowlist');
        }

        if (!in_array($patternType, [OutboundAllowlistEntry::TYPE_EXACT, OutboundAllowlistEntry::TYPE_SUFFIX, OutboundAllowlistEntry::TYPE_WILDCARD], true)) {
            $this->addFlash('error', 'Ungültiger Pattern-Typ.');

            return $this->redirectToRoute('app_settings_outbound_allowlist');
        }

        if ($this->repository->findOneByHostPattern($hostPattern) !== null) {
            $this->addFlash('error', sprintf('Für "%s" existiert bereits eine Freigabe.', $hostPattern));

            return $this->redirectToRoute('app_settings_outbound_allowlist');
        }

        $entry = new OutboundAllowlistEntry();
        $entry->setHostPattern($hostPattern);
        $entry->setPatternType($patternType);
        $entry->setDescription($description !== '' ? $description : null);
        $entry->setOrganizationId($this->organizationContext->getOrganizationId());

        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        $this->auditLogger->log('outbound_allowlist_create', $this->getUser(), null, 'OutboundAllowlistEntry', [
            'host_pattern' => $hostPattern,
            'pattern_type' => $patternType,
        ], 'success', 'Outbound-Ziel freigegeben');

        $this->addFlash('success', sprintf('Host "%s" wurde freigegeben.', $hostPattern));

        return $this->redirectToRoute('app_settings_outbound_allowlist');
    }

    #[Route('/settings/outbound-allowlist/{id}/toggle', name: 'app_settings_outbound_allowlist_toggle', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function toggle(string $id, Request $request): Response
    {
        $entry = $this->repository->find($id);
        if ($entry === null) {
            throw $this->createNotFoundException('Freigabe nicht gefunden.');
        }

        if (!$this->isCsrfTokenValid('outbound_allowlist_toggle_' . $entry->getHostPattern(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültiges CSRF-Token.');

            return $this->redirectToRoute('app_settings_outbound_allowlist');
        }

        $entry->setIsActive(!$entry->isActive());
        $this->entityManager->flush();

        $this->auditLogger->log('outbound_allowlist_toggle', $this->getUser(), null, 'OutboundAllowlistEntry', [
            'host_pattern' => $entry->getHostPattern(),
            'is_active' => $entry->isActive(),
        ], 'success', $entry->isActive() ? 'Freigabe aktiviert' : 'Freigabe deaktiviert');

        $this->addFlash('success', sprintf(
            'Freigabe für "%s" wurde %s.',
            $entry->getHostPattern(),
            $entry->isActive() ? 'aktiviert' : 'deaktiviert'
        ));

        return $this->redirectToRoute('app_settings_outbound_allowlist');
    }

    #[Route('/settings/outbound-allowlist/{id}/delete', name: 'app_settings_outbound_allowlist_delete', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(string $id, Request $request): Response
    {
        $entry = $this->repository->find($id);
        if ($entry === null) {
            throw $this->createNotFoundException('Freigabe nicht gefunden.');
        }

        if (!$this->isCsrfTokenValid('outbound_allowlist_delete_' . $entry->getHostPattern(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültiges CSRF-Token.');

            return $this->redirectToRoute('app_settings_outbound_allowlist');
        }

        $hostPattern = $entry->getHostPattern();
        $this->entityManager->remove($entry);
        $this->entityManager->flush();

        $this->auditLogger->log('outbound_allowlist_delete', $this->getUser(), null, 'OutboundAllowlistEntry', [
            'host_pattern' => $hostPattern,
        ], 'success', 'Outbound-Freigabe entfernt');

        $this->addFlash('success', sprintf('Freigabe für "%s" wurde entfernt.', $hostPattern));

        return $this->redirectToRoute('app_settings_outbound_allowlist');
    }
}
