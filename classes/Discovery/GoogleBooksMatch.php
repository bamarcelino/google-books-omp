<?php

declare(strict_types=1);

namespace APP\plugins\generic\googleBooks\classes\Discovery;

final class GoogleBooksMatch
{
    /** @param string[] $matchedIdentifiers */
    public function __construct(
        public bool $found,
        public ?string $volumeId = null,
        public ?string $selfLink = null,
        public ?string $infoLink = null,
        public ?string $previewLink = null,
        public array $matchedIdentifiers = [],
        public ?string $title = null,
        public ?string $publisher = null,
        public bool $ambiguous = false,
        public int $candidateCount = 0,
    ) {
    }
}
