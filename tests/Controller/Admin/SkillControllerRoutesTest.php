<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Controller\Admin;

use Escalated\Symfony\Controller\Admin\SkillController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Attribute\Route;

class SkillControllerRoutesTest extends TestCase
{
    public function testAdminSkillsRoutesExposeExpectedNames(): void
    {
        $ref = new \ReflectionClass(SkillController::class);
        $expected = ['index', 'create', 'store', 'edit', 'update', 'destroy'];
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
}
