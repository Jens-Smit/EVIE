<?php

namespace AppSecurity;

use AppEntityUser;
use AppRepositoryUserRepository;
use SymfonyComponentSecurityCoreExceptionUserNotFoundException;
use SymfonyComponentSecurityCoreUserUserInterface;
use SymfonyComponentSecurityCoreUserUserProviderInterface;

class UserProvider implements UserProviderInterface
{
    public function __construct(
        private UserRepository $userRepository
    ) {
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $user = $this->userRepository->findActiveByEmail($identifier);

        if (!$user) {
            throw new UserNotFoundException(sprintf('User with email "%s" not found.', $identifier));
        }

        return $user;
    }

    public function loadUserByUsername(string $username): UserInterface
    {
        return $this->loadUserByIdentifier($username);
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof User) {
            throw new InvalidArgumentException(sprintf('Expected instance of %s, got %s.', User::class, $user::class));
        }

        return $this->userRepository->find($user->getId());
    }

    public function supportsClass(string $class): bool
    {
        return $class === User::class || is_subclass_of($class, User::class);
    }
}
