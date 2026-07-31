<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Controller\Admin;

use Escalated\Symfony\Controller\Admin\WebhookController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Attribute\Route;

class WebhookControllerRoutesTest extends TestCase
{
    public function testAdminWebhookRoutesExposeExpectedNames(): void
    {
        $ref = new \ReflectionClass(WebhookController::class);
        $expected = ['index', 'create', 'store', 'edit', 'update', 'delete', 'deliveries', 'retry'];
        $found = [];
        foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getAttributes(Route::class) as $attr) {
                /** @var Route $route */
                $route = $attr->newInstance();
                $this->assertNotNull($route->name);
                $found[] = $route->name;
            }
        }

        foreach ($expected as $name) {
            $this->assertContains($name, $found, 'Missing route name '.$name);
        }
    }

    public function testControllerHasAdminRoutePrefix(): void
    {
        $ref = new \ReflectionClass(WebhookController::class);
        $attrs = $ref->getAttributes(Route::class);
        $this->assertNotEmpty($attrs);

        /** @var Route $route */
        $route = $attrs[0]->newInstance();
        $this->assertSame('/admin/webhooks', $route->path);
        $this->assertSame('escalated.admin.webhooks.', $route->name);
    }
}
