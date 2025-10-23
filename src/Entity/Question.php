<?php

namespace App\Entity;

use App\Repository\QuestionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: QuestionRepository::class)]
class Question
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'questions')]
    #[ORM\JoinColumn(nullable: false)]
    private Quiz $quiz;

    #[ORM\Column(length: 500)]
    private string $text;

    #[ORM\Column(type: 'json')]
    private array $choices = [];

    #[ORM\Column(length: 255)]
    private string $correctAnswer;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $explanation = null;

    // Getters/setters
    public function getId(): ?int
    {
        return $this->id;
    }
    public function getQuiz(): Quiz
    {
        return $this->quiz;
    }
    public function setQuiz(?Quiz $quiz): self
    {
        $this->quiz = $quiz;
        return $this;
    }
    public function getText(): string
    {
        return $this->text;
    }
    public function setText(string $text): self
    {
        $this->text = $text;
        return $this;
    }
    public function getChoices(): array
    {
        return $this->choices;
    }
    public function setChoices(array $choices): self
    {
        $this->choices = $choices;
        return $this;
    }
    public function getCorrectAnswer(): string
    {
        return $this->correctAnswer;
    }
    public function setCorrectAnswer(string $correctAnswer): self
    {
        $this->correctAnswer = $correctAnswer;
        return $this;
    }
    public function getExplanation(): ?string
    {
        return $this->explanation;
    }
    public function setExplanation(?string $exp): self
    {
        $this->explanation = $exp;
        return $this;
    }
}
