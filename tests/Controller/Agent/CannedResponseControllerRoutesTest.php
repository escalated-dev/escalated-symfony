<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Controller\Agent;

use Escalated\Symfony\Controller\Agent\CannedResponseController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Attribute\Route;

class CannedResponseControllerRoutesTest extends TestCase
{
    public function testAgentCannedResponseListRouteIsExposed(): void
    {
        $ref = new \ReflectionClass(CannedResponseController::class);
        $found = [];
        foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getAttributes(Route::class) as $attr) {
                /** @var Route $route */
                $route = $attr->newInstance();
                $this->assertNotNull($route->name);
                $found[$route->name] = $route;
            }
        }

        $this->assertArrayHasKey('canned_responses.index', $found);
        $this->assertSame('/canned-responses', $found['canned_responses.index']->path);
        $this->assertSame(['GET'], $found['canned_responses.index']->methods);
    }

    public function testControllerHasAgentRoutePrefix(): void
    {
        $ref = new \ReflectionClass(CannedResponseController::class);
        $attrs = $ref->getAttributes(Route::class);
        $this->assertNotEmpty($attrs);

        /** @var Route $route */
        $route = $attrs[0]->newInstance();
        $this->assertSame('/agent', $route->path);
        $this->assertSame('escalated.agent.', $route->name);
    }
}
