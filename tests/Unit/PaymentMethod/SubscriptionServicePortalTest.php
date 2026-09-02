<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Tests\Unit\PaymentMethod;

use FluentCart\App\Models\Subscription;
use Mockery;
use Vatly\API\Types\PortalSession;
use Vatly\Fluent\CustomerHandle;
use Vatly\Fluent\Vatly;
use Vatly\FluentCart\Config\VatlyConfig;
use Vatly\FluentCart\PaymentMethod\SubscriptionService;
use Vatly\FluentCart\Plugin;
use Vatly\FluentCart\Tests\TestCase;

/**
 * The customer self-service portal link is minted from the subscription's
 * stored Vatly customer id and returns the single-use hosted-portal URL.
 *
 * @covers \Vatly\FluentCart\PaymentMethod\SubscriptionService
 */
class SubscriptionServicePortalTest extends TestCase
{
    public function test_customer_portal_url_returns_session_url(): void
    {
        $handle = Mockery::mock(CustomerHandle::class);
        $handle->shouldReceive('portalSession')
            ->once()
            ->with(['returnUrl' => 'https://shop.test/account'])
            ->andReturn(new PortalSession('https://portal.vatly.test/s/abc123', '2026-01-01T00:15:00Z'));

        $vatly = Mockery::mock(Vatly::class);
        $vatly->shouldReceive('customer')->once()->with('customer_42')->andReturn($handle);

        $subscription = new Subscription();
        $subscription->vendor_customer_id = 'customer_42';

        $url = (new SubscriptionService($this->pluginWith($vatly)))
            ->customerPortalUrl($subscription, ['returnUrl' => 'https://shop.test/account']);

        self::assertSame('https://portal.vatly.test/s/abc123', $url);
    }

    public function test_customer_portal_url_returns_null_without_vatly_customer_id(): void
    {
        // No API call when the subscription has no bound Vatly customer id.
        $vatly = Mockery::mock(Vatly::class);
        $vatly->shouldNotReceive('customer');

        $subscription = new Subscription();
        $subscription->vendor_customer_id = null;

        $url = (new SubscriptionService($this->pluginWith($vatly)))->customerPortalUrl($subscription);

        self::assertNull($url);
    }

    public function test_customer_portal_url_returns_null_on_api_failure(): void
    {
        $handle = Mockery::mock(CustomerHandle::class);
        $handle->shouldReceive('portalSession')->once()->andThrow(new \RuntimeException('api down'));

        $vatly = Mockery::mock(Vatly::class);
        $vatly->shouldReceive('customer')->once()->with('customer_42')->andReturn($handle);

        $subscription = new Subscription();
        $subscription->vendor_customer_id = 'customer_42';

        $url = (new SubscriptionService($this->pluginWith($vatly)))->customerPortalUrl($subscription);

        self::assertNull($url);
    }

    private function pluginWith(Vatly $vatly): Plugin
    {
        $ref = new \ReflectionClass(Plugin::class);
        $plugin = $ref->newInstanceWithoutConstructor();

        $config = $ref->getProperty('config');
        $config->setAccessible(true);
        $config->setValue($plugin, new VatlyConfig());

        $client = $ref->getProperty('vatly');
        $client->setAccessible(true);
        $client->setValue($plugin, $vatly);

        return $plugin;
    }
}
