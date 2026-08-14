<?php

declare(strict_types=1);

namespace PKP\jobs {
    if (!class_exists(BaseJob::class, false)) {
        final class PendingDispatch
        {
            public function onConnection(?string $connection): self
            {
                return $this;
            }

            public function delay(mixed $when): self
            {
                return $this;
            }
        }

        abstract class BaseJob
        {
            // Mirrors OMP 3.5.0-5 / pkp-lib BaseJob property types.
            public $tries = 3;
            public int $backoff = 5;
            public int $timeout = 60;
            public int $maxExceptions = 3;
            public bool $failOnTimeout = false;
            public ?string $connection = null;
            public ?string $queue = null;

            public function __construct()
            {
                $this->connection = 'database';
                $this->queue = 'queue';
            }

            public static function dispatch(...$arguments): PendingDispatch
            {
                return new PendingDispatch();
            }

            public static function dispatchAfterResponse(...$arguments): PendingDispatch
            {
                return new PendingDispatch();
            }

            abstract public function handle();
        }
    }
}

namespace {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'APP\\plugins\\generic\\googleBooks\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $relative = substr($class, strlen($prefix));
        $path = dirname(__DIR__) . '/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    });
}
