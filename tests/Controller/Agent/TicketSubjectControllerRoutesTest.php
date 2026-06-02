<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Controller\Agent;

use Escalated\Symfony\Controller\Agent\TicketSubjectController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Attribute\Route;

class TicketSubjectControllerRoutesTest extends TestCase
{
    public function testAgentSubjectRoutesExposeExpectedNames(): void
    {
        $ref = new \ReflectionClass(TicketSubjectController::class);
        $found = [];
        foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getAttributes(Route::class) as $attr) {
                /** @var Route $route */
                $route = $attr->newInstance();
                $this->assertNotNull($route->name);
                $found[] = $route->name;
            }
        }

        $this->assertContains('subjects.attach', $found);
        $this->assertContains('subjects.detach', $found);
    }
}
