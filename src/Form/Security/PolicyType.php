<?php

namespace App\Form\Security;

use App\Entity\Security\Policy;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * PolicyType form for creating and editing policies.
 */
class PolicyType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('identifier', TextType::class, [
                'label' => 'Identifier',
                'help' => 'Unique identifier for this policy (e.g., email_send, file_delete)',
                'required' => true,
                'attr' => [
                    'placeholder' => 'e.g., email_send',
                    'class' => 'form-control',
                ],
            ])
            ->add('name', TextType::class, [
                'label' => 'Name',
                'help' => 'Human-readable name for this policy',
                'required' => true,
                'attr' => [
                    'placeholder' => 'e.g., Email Send Policy',
                    'class' => 'form-control',
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'help' => 'Description of what this policy does',
                'required' => false,
                'attr' => [
                    'placeholder' => 'e.g., Controls sending of emails',
                    'class' => 'form-control',
                    'rows' => 3,
                ],
            ])
            ->add('policyType', ChoiceType::class, [
                'label' => 'Policy Type',
                'help' => 'Type of policy',
                'choices' => [
                    'Action' => 'action',
                    'Resource' => 'resource',
                    'Access' => 'access',
                    'Custom' => 'custom',
                ],
                'required' => true,
                'attr' => [
                    'class' => 'form-select',
                ],
            ])
            ->add('effect', ChoiceType::class, [
                'label' => 'Effect',
                'help' => 'What happens when this policy matches',
                'choices' => [
                    'Allow' => 'allow',
                    'Deny' => 'deny',
                    'Ask (HITL)' => 'ask',
                ],
                'required' => true,
                'attr' => [
                    'class' => 'form-select',
                ],
            ])
            ->add('actions', TextType::class, [
                'label' => 'Actions',
                'help' => 'Comma-separated list of actions this policy applies to (e.g., email:send,email:read). Use * for all actions.',
                'required' => false,
                'attr' => [
                    'placeholder' => 'e.g., email:send,email:read',
                    'class' => 'form-control',
                ],
                'mapped' => false,
            ])
            ->add('resources', TextType::class, [
                'label' => 'Resources',
                'help' => 'Comma-separated list of resources this policy applies to (e.g., email,file). Leave empty for all resources.',
                'required' => false,
                'attr' => [
                    'placeholder' => 'e.g., email,file',
                    'class' => 'form-control',
                ],
                'mapped' => false,
            ])
            ->add('conditions', TextareaType::class, [
                'label' => 'Conditions (JSON)',
                'help' => 'JSON object of conditions that must be met for this policy to apply. Leave empty for no conditions.',
                'required' => false,
                'attr' => [
                    'placeholder' => 'e.g., {"userType": "admin"}',
                    'class' => 'form-control',
                    'rows' => 3,
                ],
            ])
            ->add('exceptions', TextareaType::class, [
                'label' => 'Exceptions (JSON)',
                'help' => 'JSON array of exception objects. Each exception can have conditions that override the policy.',
                'required' => false,
                'attr' => [
                    'placeholder' => 'e.g., [{"conditions": {"userId": "admin"}, "effect": "allow"}]',
                    'class' => 'form-control',
                    'rows' => 3,
                ],
            ])
            ->add('priority', IntegerType::class, [
                'label' => 'Priority',
                'help' => 'Priority of this policy (higher number = higher priority)',
                'required' => true,
                'data' => 0,
                'attr' => [
                    'placeholder' => '0',
                    'class' => 'form-control',
                    'min' => 0,
                ],
            ])
            ->add('isEnabled', CheckboxType::class, [
                'label' => 'Enabled',
                'help' => 'Whether this policy is active',
                'required' => false,
                'data' => true,
                'attr' => [
                    'class' => 'form-check-input',
                ],
            ])
            ->add('metadata', TextareaType::class, [
                'label' => 'Metadata (JSON)',
                'help' => 'Additional metadata for this policy',
                'required' => false,
                'attr' => [
                    'placeholder' => 'e.g., {"category": "email"}',
                    'class' => 'form-control',
                    'rows' => 3,
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Policy::class,
        ]);
    }
}
