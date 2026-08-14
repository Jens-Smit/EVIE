<?php

namespace App\Security;

use App\Entity\ResetPasswordRequest;
use App\Entity\User;
use App\Repository\ResetPasswordRequestRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Random\RandomException;

/**
 * Erzeugt und validiert Passwort-Zurücksetzen-Tokens.
 *
 * Das Token besteht aus einem öffentlichen "Selector" und einem
 * gehashten Verifier. In der URL wird der Selector transportiert,
 * der Verifier wird für die eigentliche Prüfung gehasht gespeichert.
 */
class ResetPasswordTokenGenerator
{
    private const LIFETIME_SECONDS = 3600; // 1 Stunde
    private const SELECTOR_LENGTH = 32; // Zeichen pro Token-Teil

    public function __construct(
        private ResetPasswordRequestRepository $repository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Erzeugt einen neuen Reset-Request für den Benutzer und gibt das
     * öffentliche Token (selector.verifier) zurück, das per E-Mail verschickt wird.
     *
     * @throws RandomException
     */
    public function generate(User $user): string
    {
        $this->repository->removeForUser($user);
        $this->entityManager->flush();

        $selector = $this->generateTokenPart();
        $verifier = $this->generateTokenPart();
        $hashedVerifier = hash('sha256', $verifier);

        $request = (new ResetPasswordRequest())
            ->setUser($user)
            ->setSelector($selector)
            ->setHashedToken($hashedVerifier)
            ->setExpiresAt(new DateTimeImmutable('+' . self::LIFETIME_SECONDS . ' seconds'));

        $this->repository->save($request, true);

        return $selector . $verifier;
    }

    /**
     * Validiert das öffentliche Token und gibt den zugehörigen Reset-Request zurück.
     */
    public function validate(string $token): ?ResetPasswordRequest
    {
        if (strlen($token) < self::SELECTOR_LENGTH * 2) {
            return null;
        }

        $selector = substr($token, 0, self::SELECTOR_LENGTH);
        $verifier = substr($token, self::SELECTOR_LENGTH);

        $request = $this->repository->findBySelector($selector);
        if ($request === null) {
            return null;
        }

        if ($request->isExpired()) {
            return null;
        }

        if (!hash_equals($request->getHashedToken(), hash('sha256', $verifier))) {
            return null;
        }

        return $request;
    }

    /**
     * Entfernt den Reset-Request nach erfolgreicher Nutzung.
     */
    public function consume(ResetPasswordRequest $request): void
    {
        $this->repository->remove($request, true);
    }

    /**
     * @throws RandomException
     */
    private function generateTokenPart(): string
    {
        return bin2hex(random_bytes(self::SELECTOR_LENGTH / 2));
    }
}
