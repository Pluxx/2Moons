<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'game_user')]
#[ORM\UniqueConstraint(name: 'uniq_game_user_email', columns: ['email'])]
#[UniqueEntity(fields: ['email'], message: 'An account with this email already exists.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    private ?string $email = null;

    #[ORM\Column(name: 'password_hash', length: 255)]
    private string $passwordHash = '';

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\OneToMany(mappedBy: 'owner', targetEntity: Planet::class)]
    private Collection $planets;

    #[ORM\OneToOne(targetEntity: Planet::class)]
    #[ORM\JoinColumn(name: 'home_planet_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Planet $homePlanet = null;

    #[ORM\Column(name: 'settled_at', type: 'bigint', options: ['default' => 0])]
    private string $settledAt = '0';
    #[ORM\Column(name: 'research_spy', type: 'smallint', options: ['unsigned' => true, 'default' => 0])]
    private int $researchSpy = 0;
    #[ORM\Column(name: 'research_energy', type: 'smallint', options: ['unsigned' => true, 'default' => 0])]
    private int $researchEnergy = 0;
    #[ORM\Column(name: 'research_combustion', type: 'smallint', options: ['unsigned' => true, 'default' => 0])]
    private int $researchCombustion = 0;
    #[ORM\Column(name: 'research_impulse', type: 'smallint', options: ['unsigned' => true, 'default' => 0])]
    private int $researchImpulse = 0;
    #[ORM\Column(name: 'research_expedition', type: 'smallint', options: ['unsigned' => true, 'default' => 0])]
    private int $researchExpedition = 0;

    public function __construct()
    {
        $this->planets = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setEmail(?string $email): void
    {
        $normalized = trim((string) $email);
        $this->email = $normalized === '' ? null : strtolower($normalized);
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getUserIdentifier(): string
    {
        return $this->email ?? '';
    }

    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function getPassword(): string
    {
        return $this->passwordHash;
    }

    public function setPasswordHash(string $passwordHash): void
    {
        $this->passwordHash = $passwordHash;
    }

    public function eraseCredentials(): void
    {
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): void
    {
        $this->createdAt = $createdAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setPlanet(?Planet $planet): void
    {
        if ($planet !== null) { $this->addPlanet($planet); }
    }

    public function getPlanet(): ?Planet
    {
        return $this->getHomePlanet();
    }

    public function addPlanet(Planet $planet): void
    {
        if (!$this->planets->contains($planet)) { $this->planets->add($planet); }
    }

    /** @return Collection<int, Planet> */
    public function getPlanets(): Collection { return $this->planets; }
    public function setHomePlanet(?Planet $planet): void { $this->homePlanet = $planet; }
    public function getHomePlanet(): ?Planet { return $this->homePlanet; }
    public function getSettledAt(): string { return $this->settledAt; }
    public function setAccountState(int $settledAt, array $research): void
    {
        $this->settledAt = (string) $settledAt;
        $this->researchSpy = $research['spy'];
        $this->researchEnergy = $research['energy'];
        $this->researchCombustion = $research['combustion'];
        $this->researchImpulse = $research['impulse'];
        $this->researchExpedition = $research['expedition'];
    }
    public function researchLevels(): array
    {
        return ['spy' => $this->researchSpy, 'energy' => $this->researchEnergy, 'combustion' => $this->researchCombustion,
            'impulse' => $this->researchImpulse, 'expedition' => $this->researchExpedition];
    }
}
