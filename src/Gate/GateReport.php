<?php

declare(strict_types=1);

namespace Farzai\DatasetKit\Gate;

/**
 * The gates' verdict on one built snapshot, bound to its manifest's sha256.
 * The publisher uploads only a snapshot whose report passed for that exact
 * manifest, so editing a file after the gates ran means no publish.
 *
 * A hard failure never passes. A threshold failure passes only when the owner
 * approved that check by name for this run (--approve=renames,...).
 */
final class GateReport
{
    /**
     * @param list<Failure> $failures
     * @param list<string> $approved
     */
    public function __construct(
        public readonly string $snapshot,
        public readonly string $manifestSha256,
        public readonly array $failures,
        public readonly array $approved,
        public readonly string $checkedAt,
    ) {
    }

    /**
     * Parses --approve. Only threshold checks can be approved.
     *
     * @param list<string> $thresholds the checks the dataset allows to be approved
     * @return list<string>
     */
    public static function approvals(?string $option, array $thresholds): array
    {
        if ($option === null || trim($option) === '') {
            return [];
        }
        $checks = array_values(array_filter(array_map('trim', explode(',', $option))));
        foreach ($checks as $check) {
            in_array($check, $thresholds, true)
                || throw new \InvalidArgumentException("{$check} cannot be approved; only these thresholds can: " . implode(', ', $thresholds));
        }

        return $checks;
    }

    public function passed(): bool
    {
        foreach ($this->failures as $failure) {
            if (!$failure->overridable || !in_array($failure->check, $this->approved, true)) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'snapshot' => $this->snapshot,
            'manifest_sha256' => $this->manifestSha256,
            'checked_at' => $this->checkedAt,
            'passed' => $this->passed(),
            'approved' => $this->approved,
            'failures' => array_map(fn (Failure $f) => [
                'check' => $f->check,
                'message' => $f->message,
                'overridable' => $f->overridable,
                'approved' => $f->overridable && in_array($f->check, $this->approved, true),
            ], $this->failures),
        ];
    }

    /** The report's path for a build: beside the uploaded tree, never inside it. */
    public static function path(string $out, string $snapshot): string
    {
        return "{$out}/gate-{$snapshot}.json";
    }
}
