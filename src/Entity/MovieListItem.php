<?php
// src/Entity/MovieListItem.php

namespace App\Entity;

use App\Repository\MovieListItemRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MovieListItemRepository::class)]
class MovieListItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private ?int $tmdbId = null;

    #[ORM\Column(length: 20)]
    private ?string $tmdbType = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $addedAt = null;

    #[ORM\ManyToOne(inversedBy: 'movieListItems')]
    #[ORM\JoinColumn(nullable: false)]
    private ?MovieList $movieList = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTmdbId(): ?int
    {
        return $this->tmdbId;
    }

    public function setTmdbId(int $tmdbId): static
    {
        $this->tmdbId = $tmdbId;
        return $this;
    }

    public function getTmdbType(): ?string
    {
        return $this->tmdbType;
    }

    public function setTmdbType(string $tmdbType): static
    {
        $this->tmdbType = $tmdbType;
        return $this;
    }

    public function getAddedAt(): ?\DateTimeImmutable
    {
        return $this->addedAt;
    }

    public function setAddedAt(\DateTimeImmutable $addedAt): static
    {
        $this->addedAt = $addedAt;
        return $this;
    }

    public function getMovieList(): ?MovieList
    {
        return $this->movieList;
    }

    public function setMovieList(?MovieList $movieList): static
    {
        $this->movieList = $movieList;
        return $this;
    }
}