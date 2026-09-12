<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\ToolCategory;
use App\Entity\ToolDefinition;
use PHPUnit\Framework\TestCase;

/**
 * Unit-Tests fuer ToolCategory-Entity.
 */
final class ToolCategoryTest extends TestCase
{
    public function testGettersAndSetters(): void
    {
        $category = new ToolCategory();
        $category
            ->setName('web_scraping')
            ->setDescription('Scraping tools');

        self::assertSame('web_scraping', $category->getName());
        self::assertSame('Scraping tools', $category->getDescription());
    }

    public function testDefaults(): void
    {
        $category = new ToolCategory();
        self::assertNull($category->getId());
        self::assertNull($category->getDescription());
        self::assertCount(0, $category->getTools());
    }

    public function testAddAndRemoveTool(): void
    {
        $category = new ToolCategory();
        $category->setName('general');
        $tool = $this->createMock(ToolDefinition::class);
        $tool->method('getCategory')->willReturn($category);
        $tool->expects(self::once())->method('setCategory')->with($category);

        $category->addTool($tool);
        self::assertCount(1, $category->getTools());

        // Hinzufuegen desselben Tools erneut -> keine Verdopplung
        $category->addTool($tool);
        self::assertCount(1, $category->getTools());
    }

    public function testRemoveToolSetsCategoryNullWhenMatching(): void
    {
        $category = new ToolCategory();
        $category->setName('general');
        $tool = new ToolDefinition();
        $tool->setName('test-tool');

        $category->addTool($tool);
        self::assertSame($category, $tool->getCategory());

        $category->removeTool($tool);

        self::assertCount(0, $category->getTools());
        self::assertNull($tool->getCategory());
    }

    public function testRemoveToolNotInCategoryDoesNothing(): void
    {
        $category = new ToolCategory();
        $tool = $this->createMock(ToolDefinition::class);
        $category->removeTool($tool);

        self::assertCount(0, $category->getTools());
        $this->addToAssertionCount(1);
    }
}
