<?php
// src/Repository/MovieListRepository.php

namespace App\Repository;

use App\Entity\MovieList;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MovieList>
 */
class MovieListRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MovieList::class);
    }

    /**
     * Récupère toutes les listes d'un utilisateur avec le nombre d'items (optimisé)
     *
     * @return MovieList[]
     */
    public function findByUserWithItemCount(User $user): array
    {
        $qb = $this->createQueryBuilder('ml')
            ->leftJoin('ml.movieListItems', 'mli')
            ->where('ml.user = :user')
            ->setParameter('user', $user)
            ->groupBy('ml.id')
            ->orderBy('ml.createdAt', 'DESC');

        $results = $qb->getQuery()->getResult();

        // Hydrate le count directement sur l'objet
        foreach ($results as $list) {
            $list->itemCount = $list->getMovieListItems()->count();
        }

        return $results;
    }

    /**
     * Trouve une liste par ID et utilisateur (sécurisé)
     */
    public function findOneByIdAndUser(int $id, User $user): ?MovieList
    {
        return $this->createQueryBuilder('ml')
            ->where('ml.id = :id')
            ->andWhere('ml.user = :user')
            ->setParameter('id', $id)
            ->setParameter('user', $user)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
