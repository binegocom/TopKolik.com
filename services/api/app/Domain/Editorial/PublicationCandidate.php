<?php

declare(strict_types=1);

namespace App\Domain\Editorial;

use DateTimeImmutable;

final readonly class PublicationCandidate
{
    /** @param list<EvidenceClaim> $claims */
    public function __construct(
        public string $classification,
        public string $headline,
        public string $body,
        public string $sourceIdentifier,
        public string $providerEventIdentifier,
        public string $licenseReference,
        public DateTimeImmutable $licenseValidUntil,
        public bool $commercialPublicationLicensed,
        public bool $automatedDerivativeUseLicensed,
        public bool $mediaUsageAllowed,
        public bool $sourceVerified,
        public bool $sourceCurrent,
        public bool $eventFinalized,
        public bool $hasContradictions,
        public bool $publishingEnabled,
        public array $claims,
    ) {}
}
