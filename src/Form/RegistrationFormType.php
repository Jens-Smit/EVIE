<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\IsTrue;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class RegistrationFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('firstName', TextType::class, [
                'label' => 'Vorname',
                'attr' => [
                    'class' => 'auth-input',
                    'placeholder' => 'Max',
                    'autocomplete' => 'given-name',
                ],
                'constraints' => [
                    new NotBlank(['message' => 'Bitte gib deinen Vornamen ein.']),
                ],
            ])
            ->add('lastName', TextType::class, [
                'label' => 'Nachname',
                'attr' => [
                    'class' => 'auth-input',
                    'placeholder' => 'Mustermann',
                    'autocomplete' => 'family-name',
                ],
                'constraints' => [
                    new NotBlank(['message' => 'Bitte gib deinen Nachnamen ein.']),
                ],
            ])
            ->add('email', EmailType::class, [
                'label' => 'E-Mail-Adresse',
                'attr' => [
                    'class' => 'auth-input',
                    'placeholder' => 'max@beispiel.de',
                    'autocomplete' => 'email',
                ],
                'constraints' => [
                    new NotBlank(['message' => 'Bitte gib eine E-Mail-Adresse ein.']),
                ],
            ])
            ->add('plainPassword', PasswordType::class, [
                'label' => 'Passwort',
                'mapped' => false,
                'attr' => [
                    'class' => 'auth-input',
                    'placeholder' => 'Mind. 8 Zeichen',
                    'autocomplete' => 'new-password',
                ],
                'constraints' => [
                    new NotBlank(['message' => 'Bitte gib ein Passwort ein.']),
                    new Length([
                        'min' => 8,
                        'minMessage' => 'Dein Passwort sollte mindestens {{ limit }} Zeichen lang sein.',
                        'max' => 4096,
                    ]),
                ],
            ])
            ->add('agreeTerms', CheckboxType::class, [
                'label' => 'Ich akzeptiere die Nutzungsbedingungen',
                'mapped' => false,
                'attr' => ['class' => 'auth-checkbox'],
                'constraints' => [
                    new IsTrue(['message' => 'Bitte akzeptiere die Nutzungsbedingungen.']),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'csrf_protection' => true,
            'csrf_field_name' => '_csrf_token',
            'csrf_token_id' => 'registration',
        ]);
    }
}
