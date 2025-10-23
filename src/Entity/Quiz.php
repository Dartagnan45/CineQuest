<?php

namespace App\Entity;

use App\Repository\QuizRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: QuizRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Quiz
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Le titre est obligatoire')]
    #[Assert\Length(
        min: 3,
        max: 255,
        minMessage: 'Le titre doit contenir au moins {{ limit }} caractères',
        maxMessage: 'Le titre ne peut pas dépasser {{ limit }} caractères'
    )]
    private string $title;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: 'Le thème est obligatoire')]
    #[Assert\Choice(
        choices: ['action', 'comedie', 'scifi', 'horreur', 'classiques', 'series', 'realisateurs', 'acteurs', 'francais', 'culture'],
        message: 'Thème invalide'
    )]
    private string $theme;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank(message: 'La difficulté est obligatoire')]
    #[Assert\Choice(
        choices: ['facile', 'moyen', 'difficile', 'expert'],
        message: 'Difficulté invalide'
    )]
    private string $difficulty;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\OneToMany(
        mappedBy: 'quiz',
        targetEntity: Question::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    #[Assert\Count(
        min: 1,
        max: 20,
        minMessage: 'Un quiz doit contenir au moins {{ limit }} question',
        maxMessage: 'Un quiz ne peut pas contenir plus de {{ limit }} questions'
    )]
    #[Assert\Valid]
    private Collection $questions;

    #[ORM\OneToMany(mappedBy: 'quiz', targetEntity: QuizResult::class, cascade: ['remove'])]
    private Collection $results;

    public function __construct()
    {
        $this->questions = new ArrayCollection();
        $this->results = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->isActive = true;
    }

    #[ORM\PreUpdate]
    public function setUpdatedAtValue(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    // Getters et setters
    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;
        return $this;
    }

    public function getTheme(): string
    {
        return $this->theme;
    }

    public function setTheme(string $theme): self
    {
        $this->theme = $theme;
        return $this;
    }

    public function getDifficulty(): string
    {
        return $this->difficulty;
    }

    public function setDifficulty(string $difficulty): self
    {
        $this->difficulty = $difficulty;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;
        return $this;
    }

    /**
     * @return Collection<int, Question>
     */
    public function getQuestions(): Collection
    {
        return $this->questions;
    }

    public function addQuestion(Question $question): self
    {
        if (!$this->questions->contains($question)) {
            $this->questions->add($question);
            $question->setQuiz($this);
        }
        return $this;
    }

    public function removeQuestion(Question $question): self
    {
        if ($this->questions->removeElement($question)) {
            if ($question->getQuiz() === $this) {
                $question->setQuiz(null);
            }
        }
        return $this;
    }

    /**
     * @return Collection<int, QuizResult>
     */
    public function getResults(): Collection
    {
        return $this->results;
    }

    public function addResult(QuizResult $result): self
    {
        if (!$this->results->contains($result)) {
            $this->results->add($result);
            $result->setQuiz($this);
        }
        return $this;
    }

    public function removeResult(QuizResult $result): self
    {
        if ($this->results->removeElement($result)) {
            if ($result->getQuiz() === $this) {
                $result->setQuiz(null);
            }
        }
        return $this;
    }

    /**
     * Calcule le score moyen de ce quiz
     */
    public function getAverageScore(): float
    {
        if ($this->results->isEmpty()) {
            return 0.0;
        }

        $total = 0;
        foreach ($this->results as $result) {
            $total += $result->getScore();
        }

        return round($total / $this->results->count(), 2);
    }

    /**
     * Compte le nombre de fois où ce quiz a été joué
     */
    public function getPlayCount(): int
    {
        return $this->results->count();
    }

    public function __toString(): string
    {
        return $this->title;
    }
}