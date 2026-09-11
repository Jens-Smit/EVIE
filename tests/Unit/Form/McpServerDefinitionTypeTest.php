<?php

declare(strict_types=1);

namespace App\Tests\Unit\Form;

use App\Entity\McpServerDefinition;
use App\Form\McpServerDefinitionType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Unit-Tests fuer McpServerDefinitionType.
 */
final class McpServerDefinitionTypeTest extends TestCase
{
    private McpServerDefinitionType $type;

    protected function setUp(): void
    {
        $this->type = new McpServerDefinitionType();
    }

    public function testBuildFormAddsExpectedFields(): void
    {
        $added = [];
        $builder = $this->createMock(FormBuilderInterface::class);
        $builder->expects(self::exactly(7))
            ->method('add')
            ->willReturnCallback(function (string $name) use ($builder, &$added): FormBuilderInterface {
                $added[] = $name;

                return $builder;
            });

        $this->type->buildForm($builder, []);

        self::assertSame(
            ['name', 'type', 'description', 'configuration', 'allowedTools', 'blockedResources', 'isActive'],
            $added,
        );
    }

    public function testConfigureOptionsSetsDataClass(): void
    {
        $resolver = new OptionsResolver();
        $this->type->configureOptions($resolver);

        $resolved = $resolver->resolve([]);
        self::assertSame(McpServerDefinition::class, $resolved['data_class']);
    }

    public function testImplementsFormTypeInterface(): void
    {
        self::assertInstanceOf(FormTypeInterface::class, $this->type);
    }
}
