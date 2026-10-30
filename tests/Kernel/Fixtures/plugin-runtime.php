<?php

declare(strict_types=1);

/*
 * A stand-in for @escalated-dev/plugin-runtime: speaks the bridge protocol
 * over stdio with one SDK plugin, `@acme/sdk-fixture`, and writes every
 * message it receives to the log file named in argv[1], so a test can see
 * exactly what the host sent. The real runtime is tested in its own repo; this
 * exercises the host's side of the bridge.
 *
 * `--die` exits at once, like a runtime that cannot start;
 * `--die-on-endpoint` dies when an endpoint or webhook is called.
 *
 * `cron` stores {schedule} in the plugin's `cron` collection. `badge`:
 * `SdkInbox` answers "for " plus ctx.currentUser's id (or "nobody"),
 * `SdkCount` answers 7, anything else fails.
 */

if (in_array('--die', $argv, true)) {
    exit(1);
}

$log = $argv[1] ?? null;
$name = '@acme/sdk-fixture';

$send = static function (array $message): void {
    fwrite(STDOUT, json_encode($message, JSON_UNESCAPED_SLASHES)."\n");
    fflush(STDOUT);
};

$nextId = 1;
/** Ask the host for a ctx.* method and wait for its answer. */
$ask = static function (string $method, array $params) use (&$nextId, $send): mixed {
    $id = 'rt-'.$nextId++;
    $send(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params]);
    while (($line = fgets(STDIN)) !== false) {
        $message = json_decode($line, true);
        if (($message['id'] ?? null) === $id && !isset($message['method'])) {
            return $message['error'] ?? $message['result'] ?? null;
        }
    }

    return null;
};

while (($line = fgets(STDIN)) !== false) {
    $message = json_decode($line, true);
    if (!is_array($message) || !isset($message['method'])) {
        continue;
    }
    if (null !== $log) {
        file_put_contents($log, json_encode(['method' => $message['method'], 'params' => $message['params'] ?? []], JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND | LOCK_EX);
    }

    $params = $message['params'] ?? [];
    $error = null;
    $result = ['ok' => true];

    switch ($message['method']) {
        case 'handshake':
            $result = ['compatible' => '1.0' === ($params['protocol_version'] ?? null), 'protocol_version' => '1.0', 'runtime_version' => 'fixture', 'http_contract_versions' => [1]];
            break;

        case 'manifest':
            $result = [$name => [
                'name' => $name,
                'version' => '0.4.0',
                'description' => 'An SDK plugin behind the bridge.',
                'config' => [],
                'pages' => [['route' => 'dash', 'component' => 'SdkDash', 'menu' => ['label' => 'SDK', 'section' => 'admin', 'position' => 1]]],
                'components' => [],
                'widgets' => [
                    ['component' => 'SdkWidget', 'label' => 'SDK', 'size' => 'full', 'hasBadge' => false],
                    ['component' => 'SdkInbox', 'label' => 'Inbox', 'order' => 101, 'hasBadge' => true],
                    ['component' => 'SdkCount', 'label' => 'Count', 'order' => 102, 'hasBadge' => true],
                    ['component' => 'SdkBroken', 'label' => 'Broken', 'order' => 103, 'hasBadge' => true],
                ],
                'actionHooks' => ['ticket.created', 'plugin.activated', 'plugin.deactivated'],
                'filterHooks' => [['hook' => 'demo.value', 'priority' => 15]],
                'endpoints' => [
                    ['method' => 'GET', 'path' => '/SdkDash'],
                    ['method' => 'POST', 'path' => '/items/:id'],
                    ['method' => 'GET', 'path' => '/peek'],
                    ['method' => 'GET', 'path' => '/boom'],
                ],
                'webhooks' => [['method' => 'POST', 'path' => '/hook']],
                'cronSchedules' => ['* * * * *', '0 9 * * *', 'not a schedule'],
            ]];
            break;

        case 'endpoint':
        case 'webhook':
            if (in_array('--die-on-endpoint', $argv, true)) {
                exit(1);
            }
            $path = $params['path'] ?? '';
            if ('/SdkDash' === $path) {
                $result = ['widgets' => 3];
            } elseif ('/boom' === $path) {
                $error = ['code' => -32000, 'message' => 'kaboom secret'];
            } elseif ('/peek' === $path) {
                // Names another plugin; the host must keep it to this one.
                $ask('ctx.store.set', ['plugin' => '@acme/escalated-plugin-crm', 'collection' => 'notes', 'key' => 'k', 'value' => 'from-sdk']);
                $result = ['stored' => $ask('ctx.store.get', ['plugin' => $name, 'collection' => 'notes', 'key' => 'k']), 'user' => $ask('ctx.currentUser', ['plugin' => $name])];
            } else {
                $result = ['__escalated_http' => 1, 'status' => 201, 'format' => 'json', 'headers' => ['x-runtime' => 'fixture'], 'body' => [
                    'kind' => $message['method'],
                    'path' => $path,
                    'params' => $params['params'] ?? null,
                    'query' => $params['query'] ?? null,
                    'body' => $params['body'] ?? null,
                    'header_names' => array_keys($params['headers'] ?? []),
                ]];
            }
            break;

        case 'cron':
            $ask('ctx.store.insert', ['plugin' => $name, 'collection' => 'cron', 'data' => ['schedule' => $params['schedule'] ?? null]]);
            break;

        case 'badge':
            $component = $params['component'] ?? null;
            if ('SdkInbox' === $component) {
                $user = $ask('ctx.currentUser', ['plugin' => $name]);
                $result = 'for '.(is_array($user) && isset($user['id']) ? $user['id'] : 'nobody');
            } elseif ('SdkCount' === $component) {
                $result = 7;
            } else {
                $error = ['code' => -32000, 'message' => 'badge failed'];
            }
            break;

        case 'filter':
            $result = in_array($name, $params['plugins'] ?? [], true) ? ($params['value'] ?? '').'|sdk' : ($params['value'] ?? null);
            break;

        case 'action':
            if ('ticket.created' === ($params['hook'] ?? null)) {
                $ask('ctx.store.insert', ['plugin' => $name, 'collection' => 'seen', 'data' => ['ticket' => $params['event']['id'] ?? null]]);
            }
            break;
    }

    if (isset($message['id'])) {
        $send(null === $error ? ['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => $result] : ['jsonrpc' => '2.0', 'id' => $message['id'], 'error' => $error]);
    }
}
