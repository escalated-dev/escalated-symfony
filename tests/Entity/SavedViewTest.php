<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Entity;

use Escalated\Symfony\Entity\SavedView;
use PHPUnit\Framework\TestCase;

class SavedViewTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $view = new SavedView();

        $this->assertNull($view->getId());
        $this->assertSame('', $view->getName());
        $this->assertSame([], $view->getFilters());
        $this->assertNull($view->getSortBy());
        $this->assertNull($view->getSortDir());
        $this->assertFalse($view->isShared());
        $this->assertFalse($view->isDefault());
        $this->assertSame(0, $view->getPosition());
        $this->assertNull($view->getColor());
        $this->assertNull($view->getIcon());
    }

    public function testFluentSetters(): void
    {
        $view = new SavedView();
        $result = $view->setName('My Queue')
            ->setUserId(42)
            ->setFilters(['status' => 'open', 'assigned_to' => 42])
            ->setSortBy('createdAt')
            ->setSortDir('DESC')
            ->setIsShared(true)
            ->setIsDefault(true)
            ->setPosition(3)
            ->setColor('#FF0000')
            ->setIcon('inbox');

        $this->assertSame($view, $result);
        $this->assertSame('My Queue', $view->getName());
        $this->assertSame(42, $view->getUserId());
        $this->assertSame(['status' => 'open', 'assigned_to' => 42], $view->getFilters());
        $this->assertSame('createdAt', $view->getSortBy());
        $this->assertSame('DESC', $view->getSortDir());
        $this->assertTrue($view->isShared());
        $this->assertTrue($view->isDefault());
        $this->assertSame(3, $view->getPosition());
        $this->assertSame('#FF0000', $view->getColor());
        $this->assertSame('inbox', $view->getIcon());
    }

    public function testToQueryFilters(): void
    {
        $view = new SavedView();
        $view->setFilters(['status' => 'open', 'priority' => 'high']);
        $view->setSortBy('priority');
        $view->setSortDir('ASC');

        $filters = $view->toQueryFilters();

        $this->assertSame('open', $filters['status']);
        $this->assertSame('high', $filters['priority']);
        $this->assertSame('priority', $filters['sort_by']);
        $this->assertSame('ASC', $filters['sort_dir']);
    }

    public function testToQueryFiltersWithoutSort(): void
    {
        $view = new SavedView();
        $view->setFilters(['status' => 'open']);

        $filters = $view->toQueryFilters();

        $this->assertSame(['status' => 'open'], $filters);
        $this->assertArrayNotHasKey('sort_by', $filters);
        $this->assertArrayNotHasKey('sort_dir', $filters);
    }

    public function testTimestampsSetOnConstruction(): void
    {
        $view = new SavedView();

        $this->assertInstanceOf(\DateTimeImmutable::class, $view->getCreatedAt());
        $this->assertInstanceOf(\DateTimeImmutable::class, $view->getUpdatedAt());
    }
}
