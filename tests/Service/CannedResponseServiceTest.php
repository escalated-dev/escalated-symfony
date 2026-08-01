<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Service;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\CannedResponse;
use Escalated\Symfony\Service\CannedResponseService;
use PHPUnit\Framework\TestCase;

class CannedResponseServiceTest extends TestCase
{
    public function testCreateBuildsAndPersistsResponse(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist');
        $em->expects($this->once())->method('flush');

        $service = new CannedResponseService($em);

        $response = $service->create([
            'title' => 'Greeting',
            'body' => 'Hi there, thanks for reaching out!',
            'category' => 'general',
            'isShared' => false,
            'createdBy' => 42,
        ]);

        $this->assertSame('Greeting', $response->getTitle());
        $this->assertSame('Hi there, thanks for reaching out!', $response->getBody());
        $this->assertSame('general', $response->getCategory());
        $this->assertFalse($response->isShared());
        $this->assertSame(42, $response->getCreatedBy());
    }

    public function testCreateDefaultsSharedTrueAndNullableFields(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $service = new CannedResponseService($em);

        $response = $service->create([
            'title' => 'No category',
            'body' => 'Body only.',
        ]);

        $this->assertTrue($response->isShared());
        $this->assertNull($response->getCategory());
        $this->assertNull($response->getCreatedBy());
    }

    public function testUpdateMutatesOnlyProvidedFields(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');

        $service = new CannedResponseService($em);

        $response = (new CannedResponse())
            ->setTitle('Old title')
            ->setBody('Old body')
            ->setCategory('billing')
            ->setIsShared(true);

        $service->update($response, [
            'title' => 'New title',
            'isShared' => false,
        ]);

        $this->assertSame('New title', $response->getTitle());
        $this->assertSame('Old body', $response->getBody());
        $this->assertSame('billing', $response->getCategory());
        $this->assertFalse($response->isShared());
    }

    public function testUpdateCanClearCategory(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $service = new CannedResponseService($em);

        $response = (new CannedResponse())->setCategory('billing');

        $service->update($response, ['category' => null]);

        $this->assertNull($response->getCategory());
    }

    public function testDeleteRemovesAndFlushes(): void
    {
        $response = new CannedResponse();

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('remove')->with($response);
        $em->expects($this->once())->method('flush');

        $service = new CannedResponseService($em);
        $service->delete($response);
    }
}
