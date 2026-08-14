<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\ChangePasswordFormType;
use App\Form\ForgotPasswordFormType;
use App\Form\RegistrationFormType;
use App\Form\ResetPasswordFormType;
use App\Repository\ResetPasswordRequestRepository;
use App\Repository\UserRepository;
use App\Security\ResetPasswordTokenGenerator;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
        private MailerInterface $mailer,
        private LoggerInterface $logger,
    ) {
    }

    #[Route('/login', name: 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        $error = $authenticationUtils->getLastAuthenticationError();
        $lastUsername = $authenticationUtils->getLastUsername();

        return $this->render('security/login.html.twig', [
            'last_username' => $lastUsername,
            'error' => $error,
        ]);
    }

    #[Route('/logout', name: 'app_logout')]
    public function logout(): void
    {
        throw new LogicException('This method can be blank - it will be intercepted by the logout key on your firewall.');
    }

    #[Route('/register', name: 'app_register')]
    public function register(Request $request): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        $user = new User();
        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $userRepository = $this->entityManager->getRepository(User::class);

            if ($userRepository->findOneBy(['email' => $user->getEmail()])) {
                $form->get('email')->addError(new FormError('Diese E-Mail-Adresse ist bereits registriert.'));
            } else {
                $plainPassword = $form->get('plainPassword')->getData();
                $user->setPassword(
                    $this->passwordHasher->hashPassword($user, $plainPassword)
                );

                $this->entityManager->persist($user);
                $this->entityManager->flush();

                $this->addFlash('success', 'Dein Konto wurde erstellt. Du kannst dich jetzt anmelden.');

                return $this->redirectToRoute('app_login');
            }
        }

        return $this->render('security/register.html.twig', [
            'registrationForm' => $form,
        ]);
    }

    #[Route('/forgot-password', name: 'app_forgot_password')]
    public function forgotPassword(
        Request $request,
        UserRepository $userRepository,
        ResetPasswordTokenGenerator $tokenGenerator,
    ): Response {
        $form = $this->createForm(ForgotPasswordFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $email = $form->get('email')->getData();
            $user = $userRepository->findActiveByEmail($email);

            // Aus Datenschutzgründen zeigen wir immer die gleiche Erfolgsmeldung,
            // unabhängig davon, ob die E-Mail existiert.
            if ($user !== null) {
                try {
                    $token = $tokenGenerator->generate($user);
                    $this->sendResetEmail($user, $token);
                } catch (\Throwable $e) {
                    $this->logger->error('Fehler beim Versenden der Reset-Mail', [
                        'exception' => $e->getMessage(),
                        'user' => $user->getUserIdentifier(),
                    ]);
                }
            }

            $this->addFlash(
                'success',
                'Falls ein Konto mit dieser E-Mail-Adresse existiert, wurde ein Reset-Link versendet.'
            );

            return $this->redirectToRoute('app_forgot_password');
        }

        return $this->render('security/forgot_password.html.twig', [
            'forgotPasswordForm' => $form,
        ]);
    }

    #[Route('/reset-password/{token}', name: 'app_reset_password')]
    public function resetPassword(
        Request $request,
        string $token,
        ResetPasswordTokenGenerator $tokenGenerator,
        ResetPasswordRequestRepository $resetRepository,
    ): Response {
        $resetRequest = $tokenGenerator->validate($token);

        if ($resetRequest === null) {
            $this->addFlash('error', 'Der Reset-Link ist ungültig oder abgelaufen. Bitte fordere einen neuen an.');
            return $this->redirectToRoute('app_forgot_password');
        }

        $form = $this->createForm(ResetPasswordFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user = $resetRequest->getUser();
            $plainPassword = $form->get('plainPassword')->getData();

            $user->setPassword(
                $this->passwordHasher->hashPassword($user, $plainPassword)
            );

            $tokenGenerator->consume($resetRequest);

            $this->entityManager->flush();

            $this->addFlash('success', 'Dein Passwort wurde erfolgreich geändert. Du kannst dich jetzt anmelden.');

            return $this->redirectToRoute('app_login');
        }

        return $this->render('security/reset_password.html.twig', [
            'resetPasswordForm' => $form,
        ]);
    }

    #[Route('/profile', name: 'app_profile')]
    public function profile(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $form = $this->createForm(ChangePasswordFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $currentPassword = $form->get('currentPassword')->getData();

            if (!$this->passwordHasher->isPasswordValid($user, $currentPassword)) {
                $form->get('currentPassword')->addError(new FormError('Das aktuelle Passwort ist nicht korrekt.'));
            } else {
                $plainPassword = $form->get('plainPassword')->getData();

                if ($this->passwordHasher->isPasswordValid($user, $plainPassword)) {
                    $form->get('plainPassword')->addError(new FormError('Das neue Passwort darf nicht mit dem aktuellen übereinstimmen.'));
                } else {
                    $user->setPassword(
                        $this->passwordHasher->hashPassword($user, $plainPassword)
                    );

                    $this->entityManager->flush();

                    $this->addFlash('success', 'Dein Passwort wurde erfolgreich geändert.');

                    return $this->redirectToRoute('app_profile');
                }
            }
        }

        return $this->render('security/profile.html.twig', [
            'changePasswordForm' => $form,
        ]);
    }

    private function sendResetEmail(User $user, string $token): void
    {
        $resetUrl = $this->generateUrl(
            'app_reset_password',
            ['token' => $token],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        $email = (new TemplatedEmail())
            ->from(new Address(
                $this->getParameter('mailer_from'),
                'EVIE'
            ))
            ->to($user->getEmail())
            ->subject('EVIE - Passwort zurücksetzen')
            ->htmlTemplate('emails/reset_password.html.twig')
            ->context([
                'user' => $user,
                'reset_url' => $resetUrl,
            ]);

        $this->mailer->send($email);
    }
}
