<?php
// src/Form/McpServerDefinitionType.php

namespace App\Form;

use App\Entity\McpServerDefinition;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formular für MCP-Server-Definitionen.
 * Ermöglicht das Erstellen und Bearbeiten von MCP-Server-Definitionen.
 */
class McpServerDefinitionType extends AbstractType
{
    /**
     * Verfügbare MCP-Server-Typen.
     */
    private const AVAILABLE_TYPES = [
        'filesystem' => 'Filesystem',
        'playwright' => 'Playwright',
        'github' => 'GitHub',
        'custom' => 'Custom',
    ];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Name',
                'help' => 'Ein eindeutiger Name für den MCP-Server (z. B. "mein_filesystem_server").',
                'attr' => [
                    'class' => 'form-input',
                    'placeholder' => 'z. B. mein_filesystem_server',
                ],
            ])

            ->add('type', ChoiceType::class, [
                'label' => 'Typ',
                'choices' => self::AVAILABLE_TYPES,
                'help' => 'Der Typ des MCP-Servers.',
                'attr' => [
                    'class' => 'form-select',
                ],
            ])

            ->add('description', TextareaType::class, [
                'label' => 'Beschreibung',
                'help' => 'Eine Beschreibung des MCP-Servers.',
                'attr' => [
                    'class' => 'form-textarea',
                    'rows' => 3,
                    'placeholder' => 'z. B. Filesystem-Server für lokale Dateien',
                ],
                'required' => false,
            ])

            ->add('configuration', TextareaType::class, [
                'label' => 'Konfiguration (JSON)',
                'help' => 'Die Konfiguration des MCP-Servers als JSON. Beispiel für filesystem: {"command": "npx", "arguments": ["-y", "@modelcontextprotocol/server-filesystem"]}',
                'attr' => [
                    'class' => 'form-textarea font-mono',
                    'rows' => 5,
                    'placeholder' => '{"command": "npx", "arguments": [...]}',
                ],
            ])

            ->add('allowedTools', TextareaType::class, [
                'label' => 'Erlaubte Tools (JSON-Array)',
                'help' => 'Liste der erlaubten Tools als JSON-Array. Leer lassen, um alle Tools zu erlauben. Beispiel: ["read_file", "list_files"]',
                'attr' => [
                    'class' => 'form-textarea font-mono',
                    'rows' => 3,
                    'placeholder' => '["read_file", "list_files"]',
                ],
                'required' => false,
            ])

            ->add('blockedResources', TextareaType::class, [
                'label' => 'Blockierte Ressourcen (JSON-Array)',
                'help' => 'Liste der blockierten Ressourcen als JSON-Array. Beispiel: ["/etc/*", "*.env"]',
                'attr' => [
                    'class' => 'form-textarea font-mono',
                    'rows' => 3,
                    'placeholder' => '["/etc/*", "*.env"]',
                ],
                'required' => false,
            ])

            ->add('isActive', CheckboxType::class, [
                'label' => 'Aktiv',
                'help' => 'Aktivieren, um den MCP-Server zu verwenden.',
                'attr' => [
                    'class' => 'form-checkbox',
                ],
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => McpServerDefinition::class,
        ]);
    }
}
