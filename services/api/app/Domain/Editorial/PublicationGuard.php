<?php

declare(strict_types=1);

namespace App\Domain\Editorial;

use DateTimeImmutable;

/**
 * Pure preliminary gate for automated publication. Actual publication additionally
 * requires transactional idempotency, RBAC, media rendering and outbox processing.
 */
final class PublicationGuard
{
    private const ALLOWED_CLASSIFICATIONS = [
        'final_match_result',
        'fixture_change',
        'official_statement',
    ];

    public function evaluate(PublicationCandidate $candidate, DateTimeImmutable $now): PublicationDecision
    {
        $reasons = [];

        if (! $candidate->publishingEnabled) {
            $reasons[] = 'PUBLISHING_DISABLED';
        }
        if (! in_array($candidate->classification, self::ALLOWED_CLASSIFICATIONS, true)) {
            $reasons[] = 'UNAPPROVED_CLASSIFICATION';
        }
        if (trim($candidate->sourceIdentifier) === '' || ! $candidate->sourceVerified || ! $candidate->sourceCurrent) {
            $reasons[] = 'UNVERIFIED_SOURCE';
        }
        if (trim($candidate->licenseReference) === '' || ! $candidate->commercialPublicationLicensed
            || ! $candidate->automatedDerivativeUseLicensed || $candidate->licenseValidUntil <= $now) {
            $reasons[] = 'SOURCE_LICENSE_NOT_CLEARED';
        }
        if (! $candidate->mediaUsageAllowed) {
            $reasons[] = 'MEDIA_RIGHTS_NOT_CLEARED';
        }
        if ($candidate->classification === 'final_match_result'
            && (! $candidate->eventFinalized || trim($candidate->providerEventIdentifier) === '')) {
            $reasons[] = 'MATCH_NOT_FINAL';
        }
        if ($candidate->hasContradictions) {
            $reasons[] = 'CONFLICTING_EVIDENCE';
        }
        if (trim($candidate->headline) === '' || trim($candidate->body) === '') {
            $reasons[] = 'EMPTY_STORY';
        }
        if ($candidate->claims === []) {
            $reasons[] = 'NO_EVIDENCE_CLAIMS';
        }
        foreach ($candidate->claims as $claim) {
            if (! $claim instanceof EvidenceClaim || trim($claim->claimId) === ''
                || trim($claim->sourceSnapshotId) === '' || ! $claim->verified) {
                $reasons[] = 'UNSUPPORTED_CLAIM';
                break;
            }
        }

        return new PublicationDecision($reasons === [], $reasons);
    }
}
