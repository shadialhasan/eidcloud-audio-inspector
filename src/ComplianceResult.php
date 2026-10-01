<?php

declare(strict_types=1);

namespace EidCloud\AudioInspector;

/**
 * Result of a broadcast/streaming compliance evaluation against an audio file's metrics.
 */
final class ComplianceResult
{
    /**
     * @param array<int, array{name: string, passed: bool, target: string, actual: string, note: string}> $checks
     * @param array<int, string> $recommendations
     */
    public function __construct(
        public readonly string $standardId,
        public readonly string $standardName,
        public readonly bool $isCompliant,
        public readonly float $targetLufs,
        public readonly float $toleranceLufs,
        public readonly float $measuredLufs,
        public readonly float $lufsDifference,
        public readonly float $maxTruePeak,
        public readonly float $measuredTruePeak,
        public readonly array $checks = [],
        public readonly array $recommendations = []
    ) {}

    /**
     * Convert compliance evaluation to associative array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'standard' => [
                'id' => $this->standardId,
                'name' => $this->standardName,
            ],
            'status' => $this->isCompliant ? 'PASS' : 'FAIL',
            'is_compliant' => $this->isCompliant,
            'summary' => [
                'target_lufs' => $this->targetLufs,
                'tolerance_lufs' => $this->toleranceLufs,
                'measured_lufs' => round($this->measuredLufs, 2),
                'gain_adjustment_needed_db' => round($this->lufsDifference, 2),
                'max_true_peak_dbtp' => $this->maxTruePeak,
                'measured_true_peak_dbtp' => round($this->measuredTruePeak, 2),
            ],
            'checks' => $this->checks,
            'recommendations' => $this->recommendations,
        ];
    }
}
