<?php
/**
 * Minimal WordPress shim loaded by `tests/bootstrap.php` for unit tests.
 *
 * The SUT references a handful of WP classes (`WP_Error`, `WP_REST_*`,
 * `wpdb`) and a constant (`ABSPATH`) at load/runtime. We declare just the
 * call surface our code touches; per-test behavioural assertions still come
 * from Brain\Monkey / Mockery, and `TestCase::setupCommonStubs()` stubs the
 * casual WP procedural functions (esc_*, wp_unslash, …) the SUT calls.
 *
 * NOTE: PHPStan does NOT load this file. szepeviktor/phpstan-wordpress
 * already provides typed stubs for everything here, and double-declaring
 * would conflict.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

if (! defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}
if (! defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}
if (! defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}
if (! defined('WEEK_IN_SECONDS')) {
    define('WEEK_IN_SECONDS', 604800);
}

if (! class_exists('WP_Error')) {
    #[\AllowDynamicProperties]
    class WP_Error
    {
        /** @param array<string, mixed> $data */
        public function __construct(public string $code = '', public string $message = '', public array $data = []) {}

        public function get_error_code(): string
        {
            return $this->code;
        }

        public function get_error_message(): string
        {
            return $this->message;
        }
    }
}

if (! class_exists('WP_REST_Request')) {
    #[\AllowDynamicProperties]
    class WP_REST_Request
    {
        /** @param array<string, string> $headers */
        public function __construct(public string $body = '', public array $headers = []) {}

        public function get_body(): string
        {
            return $this->body;
        }

        public function get_header(string $name): string
        {
            $name = strtolower($name);
            foreach ($this->headers as $key => $val) {
                if (strtolower((string) $key) === $name) {
                    return (string) $val;
                }
            }

            return '';
        }
    }
}

if (! class_exists('WP_REST_Response')) {
    #[\AllowDynamicProperties]
    class WP_REST_Response
    {
        /** @param mixed $data */
        public function __construct(public $data = null, public int $status = 200) {}

        public function get_status(): int
        {
            return $this->status;
        }

        /** @return mixed */
        public function get_data()
        {
            return $this->data;
        }
    }
}

if (! class_exists('WP_REST_Server')) {
    class WP_REST_Server
    {
        public const CREATABLE = 'POST';
        public const READABLE  = 'GET';
    }
}

/**
 * Tiny `$wpdb` shim. Just the call surface this plugin uses
 * (`query`, `prepare`, `get_var`, `insert`, `prefix`). Tests stage canned
 * return values via the public `next_*` fields and inspect issued queries
 * via the `queries` log. `reset()` is called between tests by
 * `TestCase::setUp()` so values don't bleed across.
 */
if (! class_exists('wpdb')) {
    #[\AllowDynamicProperties]
    class wpdb
    {
        public string $prefix = 'wp_';

        public int $insert_id = 0;

        /** @var array<int, array{0: string, 1: array<int, mixed>}> */
        public array $queries = [];

        /** @var mixed */
        public $next_var = null;

        /**
         * If the test wants $wpdb->query() to return a specific value, set this.
         * Default is 1 (one row affected).
         */
        public int $next_query_result = 1;

        public function prepare(string $sql, mixed ...$args): string
        {
            // Deterministic-ish: drop the placeholder distinction and just
            // string-quote everything. Tests asserting on the prepared SQL
            // shouldn't care which placeholder shape was used.
            //
            // We use preg_replace + sprintf-style positional substitution
            // (NOT vsprintf), because vsprintf chokes on a literal `%` in the
            // SQL (e.g. `WHERE name LIKE '%foo%'`). Only known WP placeholders
            // are consumed; everything else passes through verbatim.
            $values   = $args;
            $callback = static function () use (&$values): string {
                $v = array_shift($values);
                if (is_int($v) || is_float($v)) {
                    return (string) $v;
                }

                return "'" . addslashes((string) $v) . "'";
            };

            $result = preg_replace_callback('/%[sdfi]/', $callback, $sql);

            return $result ?? $sql;
        }

        /** @return mixed */
        public function get_var(string $sql)
        {
            $this->queries[] = ['get_var', [$sql]];

            return $this->next_var;
        }

        /**
         * @param array<string, mixed> $data
         * @param array<int, string>   $format
         */
        public function insert(string $table, array $data, array $format): int
        {
            $this->queries[] = ['insert', [$table, $data, $format]];
            $this->insert_id++;

            return 1;
        }

        public function query(string $sql): int
        {
            $this->queries[] = ['query', [$sql]];

            return $this->next_query_result;
        }

        public function get_charset_collate(): string
        {
            return 'DEFAULT CHARSET=utf8mb4';
        }

        public function reset(): void
        {
            $this->queries           = [];
            $this->next_var          = null;
            $this->next_query_result = 1;
            $this->insert_id         = 0;
        }
    }
}

if (! isset($GLOBALS['wpdb'])) {
    $GLOBALS['wpdb'] = new wpdb();
}
