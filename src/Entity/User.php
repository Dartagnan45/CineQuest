<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'user')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    private ?string $email = null;

    #[ORM\Column(type: 'json')]
    private array $roles = [];

    /**
     * Le mot de passe haché.
     */
    #[ORM\Column]
    private ?string $password = null;

    /**
     * Relation : un utilisateur peut avoir plusieurs listes de films.
     */
    #[ORM\OneToMany(mappedBy: 'user', targetEntity: MovieList::class, orphanRemoval: true, cascade: ['persist', 'remove'])]
    private Collection $movieLists;

    public function __construct()
    {
        $this->movieLists = new ArrayCollection();
    }

    // ===============================
    // Getters / Setters de base
    // ===============================

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;
        return $this;
    }

    /**
     * Utilisé par Symfony pour identifier l'utilisateur (email ici).
     */
    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    /**
     * Retourne les rôles de l'utilisateur.
     * Garantit que chaque utilisateur a au moins ROLE_USER.
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';
        return array_unique($roles);
    }

    public function setRoles(array $roles): static
    {
        $this->roles = $roles;
        return $this;
    }

    /**
     * Mot de passe haché.
     */
    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;
        return $this;
    }

    /**
     * Non utilisé dans les nouveaux projets Symfony.
     */
    public function getSalt(): ?string
    {
        return null;
    }

    /**
     * Supprime les données sensibles éventuelles.
     */
    public function eraseCredentials(): void
    {
        // Exemple : $this->plainPassword = null;
    }

    // ===============================
    // Gestion des MovieLists
    // ===============================

    /**
     * @return Collection<int, MovieList>
     */
    public function getMovieLists(): Collection
    {
        return $this->movieLists;
    }

    public function addMovieList(MovieList $movieList): static
    {
        if (!$this->movieLists->contains($movieList)) {
            $this->movieLists->add($movieList);
            $movieList->setUser($this);
        }

        return $this;
    }

    public function removeMovieList(MovieList $movieList): static
    {
        if ($this->movieLists->removeElement($movieList)) {
            // Déconnecte la liste de l'utilisateur
            if ($movieList->getUser() === $this) {
                $movieList->setUser(null);
            }
        }

        return $this;
    }

    // ===============================
    // Debug et affichage
    // ===============================

    public function __toString(): string
    {
        return $this->email ?? 'Utilisateur';
    }
}