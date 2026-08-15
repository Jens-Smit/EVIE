<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class ChangePasswordFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('currentPassword', PasswordType::class, [
                'label' => 'Aktuelles Passwort',
                'mapped' => false,
                'attr' => [
                    'class' => 'auth-input',
                    'autocomplete' => 'current-password',
                ],
                'constraints' => [
                    new NotBlank(['message' => 'Bitte gib dein aktuelles Passwort ein.']),
                ],
            ])
            ->add('plainPassword', PasswordType::class, [
                'label' => 'Neues Passwort',
                'mapped' => false,
                'attr' => [
                    'class' => 'auth-input',
                    'placeholder' => 'Mind. 8 Zeichen',
                    'autocomplete' => 'new-password',
                ],
                'constraints' => [
                    new NotBlank(['message' => 'Bitte gib ein neues Passwort ein.']),
                    new Length([
                        'min' => 8,
                        'minMessage' => 'Dein Passwort sollte mindestens {{ limit }} Zeichen lang sein.',
                        'max' => 4096,
                    ]),
                ],
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'Passwort ändern',
                'attr' => ['class' => 'btn btn-primary w-full'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => true,
            'csrf_field_name' => '_csrf_token',
            'csrf_token_id' => 'change_password',
        ]);
    }
}
