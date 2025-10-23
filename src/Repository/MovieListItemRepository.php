<?php
// src/Repository/MovieListItemRepository.php

namespace App\Repository;

use App\Entity\MovieList;
use App\Entity\MovieListItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MovieListItem>
 */
class MovieListItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MovieListItem::class);
    }

    /**
     * Vérifie si un item TMDb existe déjà dans une liste
     */
    public function alreadyExists(MovieList $movieList, int $tmdbId, string $tmdbType): bool
    {
        $count = $this->createQueryBuilder('mli')
            ->select('COUNT(mli.id)')
            ->where('mli.movieList = :list')
            ->andWhere('mli.tmdbId = :tmdbId')
            ->andWhere('mli.tmdbType = :tmdbType')
            ->setParameter('list', $movieList)
            ->setParameter('tmdbId', $tmdbId)
            ->setParameter('tmdbType', $tmdbType)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    /**
     * Compte les items dans une liste
     */
    public function countByList(MovieList $movieList): int
    {
        return $this->createQueryBuilder('mli')
            ->select('COUNT(mli.id)')
            ->where('mli.movieList = :list')
            ->setParameter('list', $movieList)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
