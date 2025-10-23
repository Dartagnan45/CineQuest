<?php

namespace App\Form;

use App\Entity\Question;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class QuestionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('text', TextType::class, [
                'label' => 'Question',
                'attr' => [
                    'placeholder' => 'Ex: Quel est le réalisateur de Pulp Fiction ?',
                    'class' => 'form-control'
                ],
                'constraints' => [
                    new Assert\NotBlank(message: 'La question est obligatoire'),
                    new Assert\Length(
                        min: 10,
                        max: 500,
                        minMessage: 'La question doit contenir au moins {{ limit }} caractères',
                        maxMessage: 'La question ne peut pas dépasser {{ limit }} caractères'
                    ),
                ],
            ])
            ->add('choices', TextType::class, [
                'label' => 'Réponses possibles (séparées par des virgules)',
                'mapped' => false,
                'attr' => [
                    'placeholder' => 'Ex: Quentin Tarantino, Martin Scorsese, Steven Spielberg, Christopher Nolan',
                    'class' => 'form-control'
                ],
                'help' => 'Entrez exactement 4 réponses possibles, séparées par des virgules',
            ])
            ->add('correctAnswer', TextType::class, [
                'label' => 'Réponse correcte',
                'attr' => [
                    'placeholder' => 'Ex: Quentin Tarantino',
                    'class' => 'form-control'
                ],
                'constraints' => [
                    new Assert\NotBlank(message: 'La réponse correcte est obligatoire'),
                ],
            ])
            ->add('explanation', TextareaType::class, [
                'label' => 'Explication (optionnelle)',
                'required' => false,
                'attr' => [
                    'placeholder' => 'Expliquez pourquoi cette réponse est correcte...',
                    'class' => 'form-control',
                    'rows' => 3
                ],
                'constraints' => [
                    new Assert\Length(
                        max: 1000,
                        maxMessage: 'L\'explication ne peut pas dépasser {{ limit }} caractères'
                    ),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Question::class,
        ]);
    }
}