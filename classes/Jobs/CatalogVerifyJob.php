<?php

declare(strict_types=1);

/**
 * Compatibility wrapper retained for jobs serialized by 0.1.0.x.
 * New dashboard discovery uses CatalogDiscoveryJob directly.
 */

namespace APP\plugins\generic\googleBooks\classes\Jobs;


final class CatalogVerifyJob extends GoogleBooksJob
{
    private const CHECKPOINTS_HOURS = [6, 24, 72, 168];

    public int $timeout = 120;

    public function __construct(
        public int $contextId,
        public ?int $userId = null,
        public int $attemptNumber = 0,
        public bool $automatic = false,
    ) {
        parent::__construct();
    }

    public function handle(): void
    {
        CatalogDiscoveryJob::dispatch($this->contextId, $this->userId);
    }

    public static function delayHoursForAttempt(int $attemptNumber): int
    {
        if ($attemptNumber < 1 || $attemptNumber > count(self::CHECKPOINTS_HOURS)) {
            throw new \InvalidArgumentException('Invalid Google Books catalog verification attempt.');
        }
        $index = $attemptNumber - 1;
        return $index === 0
            ? self::CHECKPOINTS_HOURS[0]
            : self::CHECKPOINTS_HOURS[$index] - self::CHECKPOINTS_HOURS[$index - 1];
    }
}
