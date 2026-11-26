<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Tests\Unit;

use Vatly\FluentCart\PaymentMethod\VatlySettings;
use Vatly\FluentCart\Plugin;
use Vatly\FluentCart\Tests\TestCase;

/**
 * Sends a real request to a PHP built-in web server on 127.0.0.1 that echoes
 * the User-Agent it received (see tests/Fixtures/user-agent-echo-server.php).
 */
final class UserAgentTest extends TestCase
{
    /** @var resource|null */
    private static $server;

    /** @var array<int, resource> */
    private static array $pipes = [];

    private static string $baseUrl;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port   = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        self::$server  = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", dirname(__DIR__) . '/Fixtures/user-agent-echo-server.php'],
            // Pipes, not files: Patchwork's stream wrapper hides the file descriptor.
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            self::$pipes
        );
        self::$baseUrl = "http://127.0.0.1:{$port}";

        for ($attempt = 0; $attempt < 100; $attempt++) {
            $connection = @fsockopen('127.0.0.1', $port);

            if ($connection !== false) {
                fclose($connection);

                return;
            }

            usleep(50000);
        }

        self::fail('The PHP built-in web server did not start.');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server !== null) {
            proc_terminate(self::$server);
            array_map('fclose', self::$pipes);
            proc_close(self::$server);
            self::$server = null;
        }

        parent::tearDownAfterClass();
    }

    public function test_the_user_agent_ends_with_the_plugins_package_name_and_version(): void
    {
        $GLOBALS['fluent_cart_options'][VatlySettings::METHOD_HANDLER] = [
            'test_api_key' => 'test_abcdefghijklmnopqrstuvwxyz',
            'api_url'      => self::$baseUrl,
        ];
        // Plugin is final and its constructor boots WordPress hooks.
        $plugin = (new \ReflectionClass(Plugin::class))->newInstanceWithoutConstructor();

        foreach ([$plugin->vatly(), $plugin->vatlyForTestmode(true)] as $vatly) {
            $client    = $vatly->getApiClient();
            $userAgent = $client->performHttpCall('GET', 'user-agent')->userAgent;

            self::assertSame(implode(' ', $client->getVersionStrings()), $userAgent);
            self::assertStringEndsWith(' vatly/vatly-fluentcart/' . VATLY_FLUENTCART_VERSION, $userAgent);
            self::assertSame(1, substr_count($userAgent, 'vatly/vatly-fluentcart/'));
        }
    }
}
