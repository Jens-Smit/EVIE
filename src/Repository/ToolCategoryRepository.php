<?php
// src/Repository/ToolCategoryRepository.php

namespace App\Repository;

use App\Entity\ToolCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ToolCategory>
 *
 * @method ToolCategory|null find($id, $lockMode = null, $lockVersion = null)
 * @method ToolCategory|null findOneBy(array $criteria, array $orderBy = null)
 * @method ToolCategory[]    findAll()
 * @method ToolCategory[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ToolCategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ToolCategory::class);
    }

    public function save(ToolCategory $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(ToolCategory $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Finde Kategorie nach Name
     */
    public function findOneByName(string $name): ?ToolCategory
    {
        return $this->createQueryBuilder('tc')
            ->andWhere('tc.name = :name')
            ->setParameter('name', $name)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Erstelle Standard-Kategorien
     */
    public function createDefaultCategories(): array
    {
        $categories = [
            [
                'name' => 'web_scraping',
                'description' => 'Webseiten durchsuchen, analysieren und Inhalte extrahieren',
                'color' => '#3b82f6'
            ],
            [
                'name' => 'data_analysis',
                'description' => 'Daten analysieren, Statistiken berechnen, Muster erkennen',
                'color' => '#10b981'
            ],
            [
                'name' => 'communication',
                'description' => 'E-Mails, Nachrichten, Social Media verwalten',
                'color' => '#8b5cf6'
            ],
            [
                'name' => 'api_integration',
                'description' => 'APIs anbinden, OAuth, REST, GraphQL',
                'color' => '#f59e0b'
            ],
            [
                'name' => 'document_processing',
                'description' => 'PDFs, Excel-Dateien, Dokumente verarbeiten',
                'color' => '#ef4444'
            ],
            [
                'name' => 'code_generation',
                'description' => 'Code generieren, analysieren, testen',
                'color' => '#16a34a'
            ],
            [
                'name' => 'project_management',
                'description' => 'Aufgaben, Termine, Projekte verwalten',
                'color' => '#06b6d4'
            ],
            [
                'name' => 'general',
                'description' => 'Allgemeine Tools und Funktionen',
                'color' => '#6b7280'
            ]
        ];

        $createdCategories = [];
        foreach ($categories as $categoryData) {
            $category = $this->findOneByName($categoryData['name']);
            if (!$category) {
                $category = new ToolCategory();
                $category->setName($categoryData['name']);
                $category->setDescription($categoryData['description']);
                $category->setColor($categoryData['color']);
                $this->save($category, true);
                $createdCategories[] = $category;
            }
        }

        return $createdCategories;
    }
}
