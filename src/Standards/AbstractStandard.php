<?php

declare(strict_types=1);

namespace EidCloud\AudioInspector\Standards;

use EidCloud\AudioInspector\AudioMetrics;
use EidCloud\AudioInspector\ComplianceResult;

/**
 * Base abstract class providing common validation and recommendation logic.
 */
abstract class AbstractStandard implements StandardInterface
{
    public function validate(AudioMetrics $metrics): ComplianceResult
    {
        $checks = [];
        $recommendations = [];
        $isCompliant = true;

        $targetLufs = $this->getTargetLufs();
        $tolerance = $this->getLufsTolerance();
        $measuredLufs = $metrics->integratedLufs;
        $lufsDiff = $targetLufs - $measuredLufs; // Positive means boost needed, negative means cut needed

        // 1. Integrated Loudness check
        $lufsPassed = abs($lufsDiff) <= $tolerance;
        if (!$lufsPassed) {
            $isCompliant = false;
        }
        $checks[] = [
            'name' => 'Integrated Loudness',
            'passed' => $lufsPassed,
            'target' => sprintf('%.1f LUFS (±%.1f LU)', $targetLufs, $tolerance),
            'actual' => sprintf('%.2f LUFS', $measuredLufs),
            'note' => $lufsPassed ? 'Within broadcast tolerance' : ($lufsDiff < 0 ? 'Too loud' : 'Too quiet'),
        ];

        // 2. Maximum True Peak check
        $maxTp = $this->getMaxTruePeak();
        $measuredTp = $metrics->truePeakDbtp;
        $tpPassed = $measuredTp <= $maxTp;
        if (!$tpPassed) {
            $isCompliant = false;
        }
        $checks[] = [
            'name' => 'Maximum True Peak',
            'passed' => $tpPassed,
            'target' => sprintf('<= %.1f dBTP', $maxTp),
            'actual' => sprintf('%.2f dBTP', $measuredTp),
            'note' => $tpPassed ? 'Within inter-sample headroom' : 'Exceeds maximum peak ceiling',
        ];

        // 3. Loudness Range (LRA) check
        $maxLra = $this->getMaxLra();
        if ($maxLra !== null) {
            $lraPassed = $metrics->loudnessRangeLra <= $maxLra;
            if (!$lraPassed) {
                $isCompliant = false;
            }
            $checks[] = [
                'name' => 'Loudness Range (LRA)',
                'passed' => $lraPassed,
                'target' => sprintf('<= %.1f LU', $maxLra),
                'actual' => sprintf('%.2f LU', $metrics->loudnessRangeLra),
                'note' => $lraPassed ? 'Consistent dynamic profile' : 'Exceeds maximum recommended dynamic range',
            ];
        }

        // 4. Sample Clipping Check
        $clippingPassed = $metrics->clippedSamples === 0;
        if (!$clippingPassed) {
            // Note: clipping can be a warning or failure depending on strictness
            $checks[] = [
                'name' => 'Digital Sample Clipping',
                'passed' => false,
                'target' => '0 clipped samples',
                'actual' => sprintf('%d samples (%.3f%%)', $metrics->clippedSamples, $metrics->clippedRatioPercent),
                'note' => 'Hard digital saturation detected',
            ];
            $isCompliant = false;
        } else {
            $checks[] = [
                'name' => 'Digital Sample Clipping',
                'passed' => true,
                'target' => '0 clipped samples',
                'actual' => '0 samples (Clean)',
                'note' => 'No digital full-scale clipping',
            ];
        }

        // Generate Actionable Mastering Recommendations
        if (abs($lufsDiff) > $tolerance) {
            if ($lufsDiff < 0) {
                $recommendations[] = sprintf(
                    'Gain Reduction: Lower master output gain by %.1f dB to align with %s target of %.1f LUFS.',
                    abs($lufsDiff),
                    $this->getName(),
                    $targetLufs
                );
            } else {
                $recommendations[] = sprintf(
                    'Gain Makeup: Apply +%.1f dB of clean gain or compression makeup to reach %s target of %.1f LUFS.',
                    $lufsDiff,
                    $this->getName(),
                    $targetLufs
                );
            }
        }

        if (!$tpPassed) {
            $excessTp = $measuredTp - $maxTp;
            $recommendations[] = sprintf(
                'True Peak Limiting: Audio exceeds peak ceiling by +%.2f dB. Insert a True Peak limiter configured with a ceiling of %.1f dBTP.',
                $excessTp,
                $maxTp
            );
        }

        if (!$clippingPassed) {
            $recommendations[] = sprintf(
                'Anti-Clipping: Detected %d clipped samples. Check DAW mix bus and lower pre-limiter levels to prevent DAC distortion.',
                $metrics->clippedSamples
            );
        }

        if ($maxLra !== null && $metrics->loudnessRangeLra > $maxLra) {
            $recommendations[] = sprintf(
                'Dynamic Control: LRA of %.1f LU exceeds the recommended %.1f LU. Consider upward compression or subtle dialogue leveling.',
                $metrics->loudnessRangeLra,
                $maxLra
            );
        }

        if (empty($recommendations)) {
            $recommendations[] = sprintf('Audio fully meets %s broadcast & distribution requirements. No changes needed.', $this->getName());
        }

        return new ComplianceResult(
            standardId: $this->getId(),
            standardName: $this->getName(),
            isCompliant: $isCompliant,
            targetLufs: $targetLufs,
            toleranceLufs: $tolerance,
            measuredLufs: $measuredLufs,
            lufsDifference: $lufsDiff,
            maxTruePeak: $maxTp,
            measuredTruePeak: $measuredTp,
            checks: $checks,
            recommendations: $recommendations
        );
    }
}
