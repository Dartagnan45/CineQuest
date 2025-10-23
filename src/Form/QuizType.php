<?php

namespace App\Form;

use App\Entity\Quiz;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class QuizType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Titre du Quiz',
                'attr' => [
                    'placeholder' => 'Ex: Les Classiques du Cinéma',
                    'class' => 'form-control'
                ],
                'constraints' => [
                    new Assert\NotBlank(message: 'Le titre est obligatoire'),
                    new Assert\Length(min: 3, max: 255),
                ],
            ])
            ->add('theme', ChoiceType::class, [
                'label' => 'Thème',
                'choices' => [
                    'Films d\'Action' => 'action',
                    'Comédies' => 'comedie',
                    'Science-Fiction' => 'scifi',
                    'Films d\'Horreur' => 'horreur',
                    'Classiques' => 'classiques',
                    'Séries TV' => 'series',
                    'Réalisateurs' => 'realisateurs',
                    'Acteurs & Actrices' => 'acteurs',
                    'Cinéma Français' => 'francais',
                    'Culture Générale' => 'culture',
                ],
                'attr' => ['class' => 'form-select'],
            ])
            ->add('difficulty', ChoiceType::class, [
                'label' => 'Difficulté',
                'choices' => [
                    '🌱 Facile' => 'facile',
                    '⚡ Moyen' => 'moyen',
                    '🔥 Difficile' => 'difficile',
                    '💀 Expert' => 'expert',
                ],
                'attr' => ['class' => 'form-select'],
            ])
            ->add('questions', CollectionType::class, [
                'entry_type' => QuestionType::class,
                'entry_options' => [
                    'label' => false,
                ],
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'label' => false,
                'attr' => [
                    'class' => 'questions-collection',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Quiz::class,
        ]);
    }
}