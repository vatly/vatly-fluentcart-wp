<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Webhook;

use RuntimeException;

/**
 * A webhook names a Vatly subscription that FluentCart doesn't know (yet).
 *
 * The IPN answers 404 `subscription_not_found`. Vatly retries every non-2xx
 * response, so the webhook is redelivered later, while a 500 stays reserved
 * for real processing failures.
 */
final class SubscriptionNotFoundException extends RuntimeException
{
}
