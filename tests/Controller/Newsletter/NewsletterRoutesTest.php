<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Controller\Newsletter;

use Escalated\Symfony\Controller\Newsletter\Admin\NewsletterController;
use Escalated\Symfony\Controller\Newsletter\Admin\NewsletterListController;
use Escalated\Symfony\Controller\Newsletter\Admin\NewsletterSettingsController;
use Escalated\Symfony\Controller\Newsletter\Admin\NewsletterTemplateController;
use Escalated\Symfony\Controller\Newsletter\Public\NewsletterTrackingController;
use Escalated\Symfony\Controller\Newsletter\Public\NewsletterUnsubscribeController;
use Escalated\Symfony\Controller\Newsletter\Public\NewsletterViewInBrowserController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Attribute\Route;

class NewsletterRoutesTest extends TestCase
{
    public function testAdminCampaignRoutesMatchContractNames(): void
    {
        $this->assertSame([
            'escalated.admin.newsletters.index',
            'escalated.admin.newsletters.create',
            'escalated.admin.newsletters.store',
            'escalated.admin.newsletters.preview',
            'escalated.admin.newsletters.testSend',
            'escalated.admin.newsletters.show',
            'escalated.admin.newsletters.edit',
            'escalated.admin.newsletters.update',
            'escalated.admin.newsletters.destroy',
        ], $this->routeNames(NewsletterController::class));
    }

    public function testAdminListRoutesMatchContractNames(): void
    {
        $this->assertSame([
            'escalated.admin.newsletters.lists.index',
            'escalated.admin.newsletters.lists.create',
            'escalated.admin.newsletters.lists.store',
            'escalated.admin.newsletters.lists.show',
            'escalated.admin.newsletters.lists.update',
            'escalated.admin.newsletters.lists.destroy',
            'escalated.admin.newsletters.lists.members.add',
            'escalated.admin.newsletters.lists.members.remove',
            'escalated.admin.newsletters.lists.import',
        ], $this->routeNames(NewsletterListController::class));
    }

    public function testAdminTemplateAndSettingsRoutesMatchContractNames(): void
    {
        $this->assertSame([
            'escalated.admin.newsletters.templates.index',
            'escalated.admin.newsletters.templates.create',
            'escalated.admin.newsletters.templates.store',
            'escalated.admin.newsletters.templates.show',
            'escalated.admin.newsletters.templates.update',
            'escalated.admin.newsletters.templates.destroy',
        ], $this->routeNames(NewsletterTemplateController::class));

        $this->assertSame([
            'escalated.admin.newsletters.settings.show',
            'escalated.admin.newsletters.settings.update',
        ], $this->routeNames(NewsletterSettingsController::class));
    }

    public function testPublicRoutesMatchContractNames(): void
    {
        $this->assertSame([
            'escalated.newsletters.public.open',
            'escalated.newsletters.public.click',
        ], $this->routeNames(NewsletterTrackingController::class));

        $this->assertSame([
            'escalated.newsletters.public.unsubscribe.show',
            'escalated.newsletters.public.unsubscribe.store',
        ], $this->routeNames(NewsletterUnsubscribeController::class));

        $this->assertSame([
            'escalated.newsletters.public.view',
        ], $this->routeNames(NewsletterViewInBrowserController::class));
    }

    /**
     * @param class-string $class
     *
     * @return array<int, string>
     */
    private function routeNames(string $class): array
    {
        $ref = new \ReflectionClass($class);
        $prefix = '';
        foreach ($ref->getAttributes(Route::class) as $attr) {
            $route = $attr->newInstance();
            $prefix = (string) $route->name;
        }

        $names = [];
        foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $class) {
                continue;
            }
            foreach ($method->getAttributes(Route::class) as $attr) {
                $route = $attr->newInstance();
                $names[] = $prefix.(string) $route->name;
            }
        }

        return $names;
    }
}
