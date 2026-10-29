<?php

declare(strict_types=1);

namespace Escalated\Symfony\Plugin;

/**
 * A native plugin whose dashboard widgets show a live count: the native form
 * of an SDK widget's `badge` function. Mark the widget `hasBadge: true` in the
 * manifest; while `escalated.plugins` is built for a viewer,
 * {@see widgetBadge()} is asked as that viewer (`$ctx->currentUser()`), and
 * the answer is the widget's `badge`. A throw, or anything that is not a
 * number, a string or null, makes it `null`.
 */
interface WidgetBadgePluginInterface
{
    public function widgetBadge(string $component, PluginContext $ctx): int|float|string|null;
}
