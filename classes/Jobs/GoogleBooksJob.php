<?php

declare(strict_types=1);

/**
 * Queue base class for Google Books Integration jobs.
 *
 * Copyright (c) 2026 Bruno Cesar Alves Marcelino / Scientia International
 * Distributed under the GNU GPL v3 or later.
 */

namespace APP\plugins\generic\googleBooks\classes\Jobs;

use PKP\jobs\BaseJob;

/**
 * Keep Google Books network/catalogue work outside the foreground request.
 *
 * OMP supports a synchronous Laravel queue connection. That is useful for
 * debugging, but it is unsafe for Google Books operations because a catalogue
 * discovery may perform many external HTTP calls. In that configuration the
 * plugin falls back to OMP's built-in database queue. OMP's JobRunner/worker
 * can then execute the work after the response or from the configured worker.
 */
abstract class GoogleBooksJob extends BaseJob
{
    public function __construct()
    {
        parent::__construct();

        $connection = trim((string) ($this->connection ?? ''));
        if ($connection === '' || strtolower($connection) === 'sync') {
            $this->connection = 'database';
        }
    }
}
