<?php

namespace AppController;

use AppEntityUser;
use AppRepositoryUserRepository;
use SymfonyBundleFrameworkBundleControllerAbstractController;
use SymfonyComponentHttpFoundationRequest;
use SymfonyComponentHttpFoundationResponse;
use SymfonyComponentPasswordHasherHasherUserPasswordHasherInterface;
use SymfonyComponentRoutingAnnotationRoute;
use SymfonyComponentSecurityHttpAuthenticationAuthenticationUtils;

class SecurityController extends AbstractController
{
    #[Route('/login', name: 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        $error = $authenticationUtils->getLastAuthenticationError();
        $lastUsername = $authenticationUtils->getLastUsername();

        return $this->render('security/login.html.twig', [
            'last_username' => $lastUsername,
            'error' => $error
        ]);
    }

    #[Route('/logout', name: 'app_logout')]
    public function logout(): void
    {
        // Symfony handles logout automatically
        throw new LogicException('This method can be blank - it will be intercepted by the logout key on your firewall.');
    }

    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        UserRepository $userRepository,
        UserPasswordHasherInterface $passwordHasher
    ): Response {
        if ($request->isMethod('POST')) {
            $email = $request->request->get('email');
            $password = $request->request->get('password');
            $firstName = $request->request->get('first_name');
            $lastName = $request->request->get('last_name');

            // Prüfe ob User bereits existiert
            if ($userRepository->findByEmail($email)) {
                $this->addFlash('error', 'Ein User mit dieser E-Mail existiert bereits.');
                return $this->redirectToRoute('app_register');
            }

            // Erstelle neuen User
            $user = new User();
            $user->setEmail($email);
            $user->setPassword($passwordHasher->hash($password));
            $user->setFirstName($firstName);
            $user->setLastName($lastName);
            $user->addRole('ROLE_USER');

            $userRepository->save($user, true);

            $this->addFlash('success', 'Registrierung erfolgreich! Bitte melden Sie sich an.');
            return $this->redirectToRoute('app_login');
        }

        return $this->render('security/register.html.twig');
    }

    #[Route('/dashboard', name: 'app_dashboard')]
    public function dashboard(): Response
    {
        return $this->render('dashboard/index.html.twig');
    }
}
