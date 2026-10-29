<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Fixtures\Plugin;

use Escalated\Symfony\Plugin\AbstractPlugin;
use Escalated\Symfony\Plugin\PluginContext;

/**
 * A native plugin with one of everything the contract has: pages (one behind
 * a capability, one malformed), slot components, a widget, endpoints with a
 * `:id` segment, webhooks and a filter. Inactive until a test activates it.
 */
final class CrmPlugin extends AbstractPlugin
{
    public const NAME = '@acme/escalated-plugin-crm';
    public const SLUG = 'acme--escalated-plugin-crm';
    public const SECRET_ROLE = 'ROLE_PLUGIN_SECRET';

    public function manifest(): array
    {
        return [
            'name' => self::NAME,
            'version' => '1.2.0',
            'description' => 'Links tickets to CRM contacts.',
            'author' => 'Acme',
            'homepage' => 'https://acme.example/crm',
            'pages' => [
                ['route' => '/settings', 'component' => 'CrmSettings', 'menu' => ['label' => 'CRM', 'section' => 'admin', 'position' => 5, 'icon' => 'link']],
                ['route' => 'agent-view', 'component' => 'CrmAgent', 'layout' => 'agent', 'menu' => ['label' => 'CRM lookups', 'section' => 'agent', 'position' => 7]],
                ['route' => 'secret', 'component' => 'CrmSecret', 'capability' => self::SECRET_ROLE, 'menu' => ['label' => 'Secret', 'section' => 'admin']],
                ['route' => '', 'component' => 'Broken'],
            ],
            'components' => [
                ['page' => 'ticket.show', 'slot' => 'sidebar', 'component' => 'CrmPanel', 'props' => ['compact' => true], 'order' => 3],
                ['page' => 'ticket.show', 'slot' => 'sidebar', 'component' => 'CrmSecretPanel', 'capability' => self::SECRET_ROLE],
            ],
            'widgets' => [
                ['component' => 'CrmStats', 'label' => 'CRM contacts', 'size' => 'half', 'order' => 2],
            ],
            'endpoints' => [
                ['method' => 'GET', 'path' => '/CrmSettings'],
                ['method' => 'GET', 'path' => '/contacts/:id'],
                ['method' => 'POST', 'path' => '/contacts/:id'],
                ['method' => 'GET', 'path' => '/boom'],
                ['method' => 'GET', 'path' => '/secret', 'capability' => self::SECRET_ROLE],
                ['method' => 'POST', 'path' => '/emit'],
                ['method' => 'GET', 'path' => '/teapot'],
            ],
            'webhooks' => [
                ['method' => 'POST', 'path' => '/inbound'],
                ['method' => 'POST', 'path' => '/boom'],
            ],
            'filterHooks' => [['hook' => 'demo.value', 'priority' => 20]],
        ];
    }

    public function onActivate(PluginContext $ctx): void
    {
        PluginRecorder::record(self::NAME, 'onActivate');
    }

    public function onDeactivate(PluginContext $ctx): void
    {
        PluginRecorder::record(self::NAME, 'onDeactivate');
    }

    public function handleAction(string $hook, array $event, PluginContext $ctx): void
    {
        PluginRecorder::record(self::NAME, 'action', ['hook' => $hook, 'event' => $event]);
    }

    public function applyFilter(string $hook, mixed $value, PluginContext $ctx): mixed
    {
        PluginRecorder::record(self::NAME, 'filter', ['hook' => $hook]);

        return $value.'|crm';
    }

    public function handleEndpoint(string $method, string $path, array $request, PluginContext $ctx): mixed
    {
        PluginRecorder::record(self::NAME, 'endpoint', ['hook' => $method.' '.$path, 'request' => $request]);

        return match ($path) {
            '/CrmSettings' => ['api_key' => 'set', 'user' => $ctx->currentUser()],
            '/boom' => throw new \RuntimeException('secret failure detail'),
            '/emit' => $this->emit($ctx),
            '/teapot' => ['__escalated_http' => 1, 'status' => 418, 'format' => 'text', 'headers' => ['x-plugin' => 'crm'], 'body' => 'short and stout'],
            default => ['handled' => $method.' '.$path, 'params' => $request['params'], 'query' => $request['query'], 'body' => $request['body'], 'headers' => $request['headers']],
        };
    }

    public function handleWebhook(string $method, string $path, array $request, PluginContext $ctx): mixed
    {
        PluginRecorder::record(self::NAME, 'webhook', ['hook' => $method.' '.$path, 'request' => $request]);
        if ('/boom' === $path) {
            throw new \RuntimeException('secret failure detail');
        }

        return ['received' => $request['body'], 'user' => $ctx->currentUser()];
    }

    /**
     * @return array<string, bool>
     */
    private function emit(PluginContext $ctx): array
    {
        $ctx->emit('crm.synced', ['contacts' => 3]);
        $ctx->emit('ticket.created', ['id' => 999]);
        $ctx->emit('plugin.activated', ['plugin' => 'forged']);

        return ['emitted' => true];
    }
}
