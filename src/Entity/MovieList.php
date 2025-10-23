<?php
// src/Entity/MovieList.php

namespace App\Entity;

use App\Repository\MovieListRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MovieListRepository::class)]
class MovieList
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /**
     * Indique si c'est une liste système (non supprimable)
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isSystem = false;

    #[ORM\ManyToOne(inversedBy: 'movieLists')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    /**
     * @var Collection<int, MovieListItem>
     */
    #[ORM\OneToMany(
        targetEntity: MovieListItem::class,
        mappedBy: 'movieList',
        orphanRemoval: true,
        cascade: ['persist', 'remove']
    )]
    private Collection $movieListItems;

    public function __construct()
    {
        $this->movieListItems = new ArrayCollection();
        $this->isSystem = false;
    }

    // ========================================================================
    // GETTERS & SETTERS
    // ========================================================================

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function isSystem(): bool
    {
        return $this->isSystem;
    }

    public function setIsSystem(bool $isSystem): static
    {
        $this->isSystem = $isSystem;
        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;
        return $this;
    }

    /**
     * @return Collection<int, MovieListItem>
     */
    public function getMovieListItems(): Collection
    {
        return $this->movieListItems;
    }

    public function addMovieListItem(MovieListItem $movieListItem): static
    {
        if (!$this->movieListItems->contains($movieListItem)) {
            $this->movieListItems->add($movieListItem);
            $movieListItem->setMovieList($this);
        }
        return $this;
    }

    public function removeMovieListItem(MovieListItem $movieListItem): static
    {
        if ($this->movieListItems->removeElement($movieListItem)) {
            // set the owning side to null (unless already changed)
            if ($movieListItem->getMovieList() === $this) {
                $movieListItem->setMovieList(null);
            }
        }
        return $this;
    }

}