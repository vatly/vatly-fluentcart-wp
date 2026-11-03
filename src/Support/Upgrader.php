<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Support;

/**
 * Version-gated upgrade routine.
 *
 * Runs on every boot and compares the stored schema version against the
 * running plugin version. When it finds an install that predates a change
 * needing a data migration, it enqueues that migration once, then stamps the
 * current version so the work never repeats.
 *
 * The stored-version option is owned here, deliberately NOT by
 * {@see Install::activate()}: the activation hook fires for both a brand-new
 * install and a reactivation of an existing (possibly out-of-date) install,
 * so stamping from there would skip the migration for the very installs that
 * need it. Instead a fresh install simply has no option yet, is treated as
 * "older than everything", and runs each migration against an empty data set
 * (a harmless no-op) before being stamped current.
 */
final class Upgrader
{
    /** Autoloaded option holding the schema version the data was last migrated to. */
    public const DB_VERSION_OPTION = 'vatly_fluentcart_db_version';

    /**
     * Version in which {@see \Vatly\FluentCart\Repositories\FluentCartSubscriptionRepository::store()}
     * began back-filling a subscription's billing schedule from Vatly. Installs
     * upgrading from before this may hold subscription rows with a null
     * `next_billing_date` (the admin renders that as "Invalid Date"), so they
     * get a one-time background backfill.
     */
    private const BILLING_BACKFILL_SINCE = '0.1.1-alpha';

    /**
     * @param string $currentVersion Running plugin version. Injectable so the
     *                               version gate is unit-testable without
     *                               redefining the plugin's VERSION constant.
     */
    public function __construct(private string $currentVersion = VATLY_FLUENTCART_VERSION) {}

    public function maybeUpgrade(): void
    {
        $stored = (string) get_option(self::DB_VERSION_OPTION, '0');

        if (version_compare($stored, $this->currentVersion, '>=')) {
            return;
        }

        if (version_compare($stored, self::BILLING_BACKFILL_SINCE, '<')) {
            SubscriptionBillingBackfill::schedule();
        }

        update_option(self::DB_VERSION_OPTION, $this->currentVersion);
    }
}
