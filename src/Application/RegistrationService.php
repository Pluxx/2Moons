<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Economy\EconomySettings;
use App\Domain\Economy\PlanetEconomyState;
use App\Entity\Planet;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final readonly class RegistrationService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
        private ClockInterface $clock,
        private EconomySettings $settings,
    ) {
    }

    public function register(User $user, string $plainPassword): User
    {
        $user->setPasswordHash($this->passwordHasher->hashPassword($user, $plainPassword));
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
        $user->setCreatedAt($now);
        $planet = new Planet($user, PlanetEconomyState::newHome($now->getTimestamp(), $this->settings));

        $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($user, $planet): void {
            $entityManager->persist($user);
            $entityManager->persist($planet);
        });

        return $user;
    }
}
