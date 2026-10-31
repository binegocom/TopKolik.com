<?php

declare(strict_types=1);

namespace App\Domain\Editorial;

final readonly class PublicationDecision
{
    /** @param list<string> $reasons */
    public function __construct(
        public bool $allowed,
        public array $reasons,
    ) {}
}
