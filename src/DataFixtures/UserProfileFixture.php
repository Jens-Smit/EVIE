<?php

namespace App\DataFixtures;

use App\Entity\UserProfile;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class UserProfileFixture extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $user = new UserProfile();
        $user->setName('Default User');
        $user->setEmail('dev@local');
        $user->setPreferences([]);

        $manager->persist($user);
        $manager->flush();

        // Optional: Referenz für andere Fixtures
        $this->addReference('default-user', $user);
    }
}
