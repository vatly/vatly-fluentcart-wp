<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Webhook;

use Vatly\Fluent\Contracts\EventDispatcherInterface;

/**
 * Bridges vatly-fluent-php's event POPOs onto WordPress action hooks so other
 * plugins can subscribe via add_action(). The action name is derived from the
 * event class — e.g. Vatly\API\Webhooks\Events\OrderPaid → vatly/order_paid.
 */
final class EventDispatcher implements EventDispatcherInterface
{
    public function dispatch(object $event): void
    {
        do_action($this->hookName($event), $event);
    }

    private function hookName(object $event): string
    {
        $short = (new \ReflectionClass($event))->getShortName();
        $snake = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $short));

        return 'vatly/' . $snake;
    }
}
