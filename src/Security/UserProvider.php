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
        $user = $this->userRepository->findByEmail($identifier);
        
        if (!$user) {
            throw new UserNotFoundException();
        }
        
        return $user;
    }

    public function loadUserByUsername(string $username): UserInterface
    {
        return $this->loadUserByIdentifier($username);
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        return $user;
    }

    public function supportsClass(string $class): bool
    {
        return $class === User::class || is_subclass_of($class, User::class);
    }
}
