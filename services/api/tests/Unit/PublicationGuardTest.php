<?php

declare(strict_types=1);

require_once __DIR__.'/../../app/Domain/Editorial/EvidenceClaim.php';
require_once __DIR__.'/../../app/Domain/Editorial/PublicationCandidate.php';
require_once __DIR__.'/../../app/Domain/Editorial/PublicationDecision.php';
require_once __DIR__.'/../../app/Domain/Editorial/PublicationGuard.php';

use App\Domain\Editorial\EvidenceClaim;
use App\Domain\Editorial\PublicationCandidate;
use App\Domain\Editorial\PublicationGuard;

$now = new DateTimeImmutable('2026-10-31T12:00:00+00:00');
$guard = new PublicationGuard();
$base = [
    'classification' => 'final_match_result',
    'headline' => 'Verified fixture result',
    'body' => 'A verified summary with source-backed claims.',
    'sourceIdentifier' => 'source-record',
    'providerEventIdentifier' => 'provider-fixture-record',
    'licenseReference' => 'approved-license-record',
    'licenseValidUntil' => new DateTimeImmutable('2027-12-31T00:00:00+00:00'),
    'commercialPublicationLicensed' => true,
    'automatedDerivativeUseLicensed' => true,
    'mediaUsageAllowed' => true,
    'sourceVerified' => true,
    'sourceCurrent' => true,
    'eventFinalized' => true,
    'hasContradictions' => false,
    'publishingEnabled' => true,
    'claims' => [new EvidenceClaim('claim-1', 'snapshot-1', true)],
];
function candidate(array $params): PublicationCandidate
{
    return new PublicationCandidate(...$params);
}
$tests = [
    ['valid licensed verified content', [], true, null],
    ['disabled publishing', ['publishingEnabled' => false], false, 'PUBLISHING_DISABLED'],
    ['unlicensed redistribution', ['commercialPublicationLicensed' => false], false, 'SOURCE_LICENSE_NOT_CLEARED'],
    ['unlicensed derivative use', ['automatedDerivativeUseLicensed' => false], false, 'SOURCE_LICENSE_NOT_CLEARED'],
    ['expired rights', ['licenseValidUntil' => new DateTimeImmutable('2026-01-01T00:00:00+00:00')], false, 'SOURCE_LICENSE_NOT_CLEARED'],
    ['unverified source', ['sourceVerified' => false], false, 'UNVERIFIED_SOURCE'],
    ['source not current', ['sourceCurrent' => false], false, 'UNVERIFIED_SOURCE'],
    ['unapproved rumors', ['classification' => 'transfer_rumor'], false, 'UNAPPROVED_CLASSIFICATION'],
    ['match not finalized', ['eventFinalized' => false], false, 'MATCH_NOT_FINAL'],
    ['match missing event id', ['providerEventIdentifier' => ' '], false, 'MATCH_NOT_FINAL'],
    ['contradictions', ['hasContradictions' => true], false, 'CONFLICTING_EVIDENCE'],
    ['empty headline', ['headline' => ''], false, 'EMPTY_STORY'],
    ['missing evidence', ['claims' => []], false, 'NO_EVIDENCE_CLAIMS'],
    ['unverified claim', ['claims' => [new EvidenceClaim('claim-1', 'snapshot-1', false)]], false, 'UNSUPPORTED_CLAIM'],
    ['missing evidence snapshot', ['claims' => [new EvidenceClaim('claim-1', '', true)]], false, 'UNSUPPORTED_CLAIM'],
    ['unlicensed media', ['mediaUsageAllowed' => false], false, 'MEDIA_RIGHTS_NOT_CLEARED'],
    ['allowed fixture change', ['classification' => 'fixture_change', 'eventFinalized' => false], true, null],
];
foreach ($tests as [$label, $change, $expected, $reason]) {
    $result = $guard->evaluate(candidate(array_replace($base, $change)), $now);
    if ($result->allowed !== $expected || ($reason !== null && ! in_array($reason, $result->reasons, true))) {
        fwrite(STDERR, "FAIL: {$label}: ".json_encode($result->reasons)."\n");
        exit(1);
    }
    echo "PASS: {$label}\n";
}
echo 'Passed '.count($tests)." guard tests.\n";
