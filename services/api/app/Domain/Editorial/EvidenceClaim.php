<?php

declare(strict_types=1);

namespace App\Domain\Editorial;

final readonly class EvidenceClaim
{
    public function __construct(
        public string $claimId,
        public string $sourceSnapshotId,
        public bool $verified,
    ) {}
}
