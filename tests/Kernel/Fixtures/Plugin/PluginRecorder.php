<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Fixtures\Plugin;

/**
 * What the fixture plugins were asked to do, in order, and what they should
 * throw on. Reset by each test.
 */
final class PluginRecorder
{
    /** @var list<array{plugin: string, call: string, hook?: string, event?: mixed, request?: array<string, mixed>}> */
    public static array $calls = [];

    /** @var list<string> "plugin:call" or "plugin:hook" that throw */
    public static array $throwOn = [];

    public static function reset(): void
    {
        self::$calls = [];
        self::$throwOn = [];
    }

    /**
     * @param array<string, mixed> $extra
     */
    public static function record(string $plugin, string $call, array $extra = []): void
    {
        self::$calls[] = ['plugin' => $plugin, 'call' => $call] + $extra;
        if (\in_array($plugin.':'.$call, self::$throwOn, true) || (isset($extra['hook']) && \in_array($plugin.':'.$extra['hook'], self::$throwOn, true))) {
            throw new \RuntimeException('fixture plugin failure: secret failure detail');
        }
    }

    /**
     * "plugin call[ hook]" for every recorded call.
     *
     * @return list<string>
     */
    public static function trail(?string $plugin = null): array
    {
        $trail = [];
        foreach (self::$calls as $call) {
            if (null === $plugin || $call['plugin'] === $plugin) {
                $trail[] = trim($call['plugin'].' '.$call['call'].' '.($call['hook'] ?? ''));
            }
        }

        return $trail;
    }

    /**
     * The events delivered to one plugin for one hook.
     *
     * @return list<mixed>
     */
    public static function events(string $plugin, string $hook): array
    {
        $events = [];
        foreach (self::$calls as $call) {
            if ($call['plugin'] === $plugin && 'action' === $call['call'] && ($call['hook'] ?? null) === $hook) {
                $events[] = $call['event'] ?? null;
            }
        }

        return $events;
    }

    /**
     * @return list<string>
     */
    public static function hooks(string $plugin): array
    {
        $hooks = [];
        foreach (self::$calls as $call) {
            if ($call['plugin'] === $plugin && 'action' === $call['call']) {
                $hooks[] = (string) ($call['hook'] ?? '');
            }
        }

        return $hooks;
    }
}
