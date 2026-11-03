<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Support;

use FluentCart\App\Models\Subscription;
use Throwable;
use Vatly\FluentCart\Plugin;
use Vatly\FluentCart\Repositories\FluentCartSubscriptionRepository;

/**
 * One-time background backfill of subscription billing dates.
 *
 * Installs upgrading across the fix that made store() back-fill the billing
 * schedule (see {@see Upgrader}) may hold Vatly-linked subscription rows whose
 * `next_billing_date` is still null, which the FluentCart admin renders as
 * "Invalid Date". This walks those rows in small batches on WP-Cron, re-fetches
 * each subscription from Vatly (the authoritative source) and fills the
 * schedule in, re-scheduling itself until the table is exhausted.
 *
 * It runs on cron rather than inline during the upgrade so the re-fetch's
 * per-row Vatly API calls never block an admin page load, and it pages forward
 * by ascending id (a stored cursor) rather than re-selecting "rows still
 * missing a date": a row Vatly cannot resolve stays null but the cursor moves
 * past it, so a single unresolvable row can never wedge the run in a loop.
 */
final class SubscriptionBillingBackfill
{
    /** WP-Cron hook the batch runner is attached to. */
    public const HOOK = 'vatly_fluentcart_backfill_subscription_billing';

    /** Autoloaded option holding the id the last batch paged up to. */
    public const CURSOR_OPTION = 'vatly_fluentcart_billing_backfill_cursor';

    /** Rows re-fetched from Vatly per cron tick. */
    public const BATCH_SIZE = 25;

    /** Attach the batch runner to its cron hook. Called unconditionally on boot. */
    public static function register(): void
    {
        add_action(self::HOOK, [self::class, 'run']);
    }

    /** Queue the first batch if one isn't already pending. */
    public static function schedule(): void
    {
        if (! wp_next_scheduled(self::HOOK)) {
            wp_schedule_single_event(time() + MINUTE_IN_SECONDS, self::HOOK);
        }
    }

    /**
     * WP-Cron callback. Delegates to {@see self::runBatch()} with the live
     * repository's backfill as the per-row filler; kept arg-less because the
     * cron event carries no args.
     */
    public static function run(): void
    {
        $repository = new FluentCartSubscriptionRepository(Plugin::instance());

        self::runBatch(static fn (Subscription $row): bool => $repository->backfillBillingSchedule($row));
    }

    /**
     * Process one batch. Pages forward from the stored cursor, fills each row's
     * billing schedule, advances the cursor and re-queues itself while rows
     * remain. Clears the cursor and stops once the table is exhausted.
     *
     * Split from {@see self::run()} and parameterised on the filler so the batch
     * orchestration is unit-testable without the final repository / live Vatly
     * re-fetch (that round-trip is integration-tier).
     *
     * @param callable(Subscription): bool $fill Fills one row's billing schedule;
     *                                           returns whether it was updated.
     */
    public static function runBatch(callable $fill): void
    {
        $cursor = (int) get_option(self::CURSOR_OPTION, 0);

        $rows = Subscription::query()
            ->where('id', '>', $cursor)
            ->whereNotNull('vendor_subscription_id')
            ->whereNull('next_billing_date')
            ->orderBy('id', 'asc')
            ->limit(self::BATCH_SIZE)
            ->get();

        $lastId    = $cursor;
        $processed = 0;
        foreach ($rows as $row) {
            $processed++;
            $lastId = (int) $row->id;

            try {
                $fill($row);
            } catch (Throwable $e) {
                error_log(sprintf(
                    '[vatly-for-fluentcart] billing backfill failed for subscription %d: %s',
                    $lastId,
                    $e->getMessage(),
                ));
            }
        }

        if ($processed === 0) {
            // Table exhausted: nothing left beyond the cursor needs a date.
            delete_option(self::CURSOR_OPTION);
            return;
        }

        update_option(self::CURSOR_OPTION, $lastId);
        wp_schedule_single_event(time() + MINUTE_IN_SECONDS, self::HOOK);
    }
}
