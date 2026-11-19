<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Tests\Unit\Support;

use Brain\Monkey\Functions;
use Vatly\FluentCart\Support\SubscriptionBillingBackfill;
use Vatly\FluentCart\Support\Upgrader;
use Vatly\FluentCart\Tests\TestCase;

/**
 * The version gate decides, from the stored schema version, whether to enqueue
 * the subscription billing-date backfill and stamp the current version. The
 * running version is injected so the gate is exercisable without redefining the
 * plugin's VERSION constant.
 *
 * @covers \Vatly\FluentCart\Support\Upgrader
 */
final class UpgraderTest extends TestCase
{
    public function test_schedules_backfill_and_stamps_when_upgrading_from_before_the_billing_fix(): void
    {
        // No option yet (returns the '0' default): a pre-Upgrader install, which
        // may hold subscriptions with a null next_billing_date.
        Functions\when('get_option')->justReturn('0');
        Functions\when('wp_next_scheduled')->justReturn(false);

        Functions\expect('wp_schedule_single_event')->once();
        Functions\expect('update_option')
            ->once()
            ->with(Upgrader::DB_VERSION_OPTION, '0.1.1-alpha');

        (new Upgrader('0.1.1-alpha'))->maybeUpgrade();

        $this->assertHookExpectations();
    }

    public function test_does_nothing_when_the_stored_version_is_already_current(): void
    {
        Functions\when('get_option')->justReturn('0.1.1-alpha');

        Functions\expect('wp_schedule_single_event')->never();
        Functions\expect('update_option')->never();

        (new Upgrader('0.1.1-alpha'))->maybeUpgrade();

        $this->assertHookExpectations();
    }

    public function test_stamps_a_newer_version_without_re_running_the_billing_backfill(): void
    {
        // Already past the backfill version: a later release bumps the stored
        // version but must not re-enqueue a migration that already ran.
        Functions\when('get_option')->justReturn('0.1.1-alpha');

        Functions\expect('wp_schedule_single_event')->never();
        Functions\expect('update_option')
            ->once()
            ->with(Upgrader::DB_VERSION_OPTION, '0.2.0');

        (new Upgrader('0.2.0'))->maybeUpgrade();

        $this->assertHookExpectations();
    }

    public function test_schedule_is_idempotent_when_an_event_is_already_pending(): void
    {
        // schedule() must not double-book: if wp-cron already has the hook
        // queued, it leaves it alone.
        Functions\when('wp_next_scheduled')->justReturn(1_700_000_000);
        Functions\expect('wp_schedule_single_event')->never();

        SubscriptionBillingBackfill::schedule();

        $this->assertHookExpectations();
    }
}
