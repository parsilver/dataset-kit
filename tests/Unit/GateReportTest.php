<?php

declare(strict_types=1);

use Farzai\DatasetKit\Gate\Failure;
use Farzai\DatasetKit\Gate\GateReport;

const THRESHOLDS = ['renames', 'postal_links'];

function report(array $failures, array $approved = []): GateReport
{
    return new GateReport('2026-10-14', str_repeat('a', 64), $failures, $approved, '2026-10-14T16:00:00Z');
}

test('no failures passes', function () {
    $this->assertTrue(report([])->passed());
});

test('a hard failure never passes, whatever is approved', function () {
    $hard = new Failure('postal_prefix', '73697 of 310304', overridable: false);
    $this->assertFalse(report([$hard])->passed());
    $this->assertFalse(report([$hard], ['postal_prefix'])->passed());
});

test('a threshold passes only when that check is approved', function () {
    $renames = new Failure('renames', '51 renames', overridable: true);
    $links = new Failure('postal_links', '3%', overridable: true);
    $this->assertFalse(report([$renames])->passed());
    $this->assertTrue(report([$renames], ['renames'])->passed());
    $this->assertFalse(report([$renames, $links], ['renames'])->passed(), 'approving one threshold does not approve another');
});

test('the report names the snapshot, its manifest and every failure', function () {
    $array = report([new Failure('renames', '51 renames', true)], ['renames'])->toArray();
    $this->assertSame('2026-10-14', $array['snapshot']);
    $this->assertSame(str_repeat('a', 64), $array['manifest_sha256']);
    $this->assertSame([['check' => 'renames', 'message' => '51 renames', 'overridable' => true, 'approved' => true]], $array['failures']);
    $this->assertTrue($array['passed']);
});

test('approving a check the dataset does not list as a threshold is refused outright', function () {
    expect(fn () => GateReport::approvals('renames,orphans', THRESHOLDS))->toThrow(InvalidArgumentException::class, 'orphans');
    $this->assertSame(['renames', 'postal_links'], GateReport::approvals('renames, postal_links', THRESHOLDS));
    $this->assertSame([], GateReport::approvals(null, THRESHOLDS));
});

test('the report lives beside the uploaded tree, never inside it', function () {
    $this->assertSame('/out/gate-2026-10-14.json', GateReport::path('/out', '2026-10-14'));
});
