<?php

declare(strict_types=1);

namespace EidCloud\AudioInspector\Report;

use EidCloud\AudioInspector\AudioMetrics;
use EidCloud\AudioInspector\ComplianceResult;

/**
 * Formats audio metrics and compliance results into ANSI-colored terminal reports and tables.
 */
final class ConsoleReport
{
    private bool $useAnsi;

    // ANSI Color escapes
    private const RESET = "\033[0m";
    private const BOLD = "\033[1m";
    private const DIM = "\033[2m";
    private const RED = "\033[31m";
    private const GREEN = "\033[32m";
    private const YELLOW = "\033[33m";
    private const BLUE = "\033[34m";
    private const MAGENTA = "\033[35m";
    private const CYAN = "\033[36m";
    private const WHITE = "\033[37m";

    private const BG_GREEN = "\033[42m\033[30m";
    private const BG_RED = "\033[41m\033[37m";
    private const BG_BLUE = "\033[44m\033[37m";

    public function __construct(bool $useAnsi = true)
    {
        // Detect if color should be disabled
        if (getenv('NO_COLOR') !== false || getenv('TERM') === 'dumb') {
            $useAnsi = false;
        }
        $this->useAnsi = $useAnsi;
    }

    /**
     * Render full console report for audio metrics and optional compliance results.
     */
    public function render(AudioMetrics $metrics, ?ComplianceResult $compliance = null): string
    {
        $out = [];
        $width = 78;

        $out[] = $this->renderHeader($width);
        $out[] = $this->renderFileCard($metrics, $width);
        $out[] = $this->renderMetricsTable($metrics, $width);

        if ($compliance !== null) {
            $out[] = $this->renderComplianceCard($compliance, $width);
        }

        $out[] = $this->color(str_repeat('─', $width), self::DIM);
        $out[] = '';

        return implode("\n", $out);
    }

    private function renderHeader(int $width): string
    {
        $lines = [];
        $lines[] = '';
        $lines[] = $this->color(str_repeat('═', $width), self::CYAN);
        $title = "🎙️  EIDCLOUD AUDIO FORENSICS & BROADCAST INSPECTOR";
        $lines[] = $this->color("  " . $title, self::BOLD . self::CYAN);
        $subtitle = "  Acoustic Telemetry, LUFS Normalization & Compliance Verification";
        $lines[] = $this->color($subtitle, self::DIM);
        $lines[] = $this->color(str_repeat('═', $width), self::CYAN);
        return implode("\n", $lines);
    }

    private function renderFileCard(AudioMetrics $m, int $width): string
    {
        $lines = [];
        $lines[] = '';
        $lines[] = $this->color("📁  AUDIO FILE METADATA", self::BOLD . self::WHITE);
        $lines[] = $this->color(str_repeat('─', $width), self::DIM);

        $data = $m->toArray()['file_info'];

        $col1 = [
            'File' => basename($m->filePath),
            'Path' => strlen($m->filePath) > 45 ? '...' . substr($m->filePath, -42) : $m->filePath,
            'Format' => $data['format'],
            'Duration' => sprintf('%s (%.2f s)', $data['duration_formatted'], $data['duration_seconds']),
        ];

        $col2 = [
            'Sample Rate' => number_format($data['sample_rate_hz']) . ' Hz',
            'Channels' => sprintf('%d (%s)', $data['channels'], $data['channel_layout']),
            'Bit Depth' => $data['bit_depth'] > 0 ? "{$data['bit_depth']}-bit" : 'Variable',
            'File Size' => $data['size_formatted'],
        ];

        foreach ($col1 as $k => $v) {
            $k2 = key($col2);
            $v2 = current($col2);
            next($col2);

            $left = sprintf("  %-12s: %-24s", $this->color($k, self::DIM), $v);
            $right = sprintf("%-13s: %s", $this->color($k2, self::DIM), $v2);
            $lines[] = $left . '  ' . $right;
        }

        return implode("\n", $lines);
    }

    private function renderMetricsTable(AudioMetrics $m, int $width): string
    {
        $lines = [];
        $lines[] = '';
        $lines[] = $this->color("📊  ACOUSTIC FORENSICS & MEASUREMENTS", self::BOLD . self::WHITE);
        $lines[] = $this->color(str_repeat('─', $width), self::DIM);

        // Header
        $lines[] = sprintf(
            "  %-26s │ %-14s │ %-12s │ %s",
            $this->color("Metric", self::BOLD),
            $this->color("Value", self::BOLD),
            $this->color("Unit", self::BOLD),
            $this->color("Forensic Status", self::BOLD)
        );
        $lines[] = $this->color("  " . str_repeat('─', 26) . "┼" . str_repeat('─', 16) . "┼" . str_repeat('─', 14) . "┼" . str_repeat('─', 18), self::DIM);

        // Rows definition
        $rows = [
            [
                'Integrated Loudness',
                sprintf('%+.2f', $m->integratedLufs),
                'LUFS',
                $this->formatLufsStatus($m->integratedLufs)
            ],
            [
                'True Peak Level',
                sprintf('%+.2f', $m->truePeakDbtp),
                'dBTP',
                $m->truePeakDbtp > -1.0 ? $this->color('PEAK RISK', self::RED) : $this->color('PROTECTED', self::GREEN)
            ],
            [
                'Sample Peak Level',
                sprintf('%+.2f', $m->samplePeakDbfs),
                'dBFS',
                $m->samplePeakDbfs >= -0.1 ? $this->color('NEAR CEILING', self::YELLOW) : $this->color('OPTIMAL', self::GREEN)
            ],
            [
                'Loudness Range (LRA)',
                sprintf('%.2f', $m->loudnessRangeLra),
                'LU',
                $m->loudnessRangeLra > 18.0 ? $this->color('WIDE DYNAMICS', self::YELLOW) : $this->color('CONTROLLED', self::GREEN)
            ],
            [
                'RMS Energy Level',
                sprintf('%+.2f', $m->rmsLevelDbfs),
                'dBFS',
                $this->color('STEADY', self::CYAN)
            ],
            [
                'Signal-to-Noise (SNR)',
                sprintf('%.2f', $m->snrDb),
                'dB',
                $m->snrDb < 30.0 ? $this->color('NOISY', self::YELLOW) : $this->color('CLEAN DYNAMICS', self::GREEN)
            ],
            [
                'Silence Ratio',
                sprintf('%.2f%%', $m->silenceRatioPercent),
                sprintf('%.2fs quiet', $m->silenceDurationSeconds),
                $m->silenceRatioPercent > 50.0 ? $this->color('HIGH SILENCE', self::YELLOW) : $this->color('CONTINUOUS', self::GREEN)
            ],
            [
                'Digital Clipping',
                (string) $m->clippedSamples,
                sprintf('%.3f%%', $m->clippedRatioPercent),
                $m->clippedSamples > 0 ? $this->color('CLIPPING DETECTED', self::RED . self::BOLD) : $this->color('PRISTINE (0 CLIPS)', self::GREEN)
            ],
            [
                'Measurement Engine',
                $m->analysisMethod,
                'native',
                $m->analysisMethod === 'ffmpeg' ? $this->color('FFmpeg Libav', self::CYAN) : $this->color('Pure PHP Engine', self::MAGENTA)
            ],
        ];

        foreach ($rows as $row) {
            $lines[] = sprintf(
                "  %-26s │ %-14s │ %-12s │ %s",
                $row[0],
                $row[1],
                $row[2],
                $row[3]
            );
        }

        return implode("\n", $lines);
    }

    private function renderComplianceCard(ComplianceResult $c, int $width): string
    {
        $lines = [];
        $lines[] = '';
        $lines[] = $this->color("⚖️   BROADCAST & STREAMING COMPLIANCE", self::BOLD . self::WHITE);
        $lines[] = $this->color(str_repeat('─', $width), self::DIM);

        $badge = $c->isCompliant
            ? $this->color("  [ COMPLIANCE PASSED ]  ", self::BG_GREEN . self::BOLD)
            : $this->color("  [ COMPLIANCE FAILED ]  ", self::BG_RED . self::BOLD);

        $lines[] = sprintf("  Standard: %-38s  %s", $this->color($c->standardName, self::BOLD . self::CYAN), $badge);
        $lines[] = '';

        // Verification checks
        $lines[] = $this->color("  Check Verification Matrix:", self::BOLD);
        foreach ($c->checks as $check) {
            $icon = $check['passed'] ? $this->color('✔ PASS', self::GREEN . self::BOLD) : $this->color('✘ FAIL', self::RED . self::BOLD);
            $lines[] = sprintf(
                "    [%s] %-26s Target: %-18s Actual: %-16s (%s)",
                $icon,
                $check['name'],
                $check['target'],
                $check['actual'],
                $check['note']
            );
        }

        // Actionable Recommendations
        if (!empty($c->recommendations)) {
            $lines[] = '';
            $lines[] = $this->color("  Mastering & Forensic Recommendations:", self::BOLD . self::YELLOW);
            foreach ($c->recommendations as $rec) {
                $lines[] = sprintf("    %s %s", $this->color('➜', self::YELLOW), $rec);
            }
        }

        return implode("\n", $lines);
    }

    private function formatLufsStatus(float $lufs): string
    {
        if ($lufs > -13.0) {
            return $this->color('VERY LOUD', self::RED);
        }
        if ($lufs >= -17.0) {
            return $this->color('STREAMING RANGE', self::CYAN);
        }
        if ($lufs >= -24.0) {
            return $this->color('BROADCAST RANGE', self::GREEN);
        }
        return $this->color('QUIET', self::YELLOW);
    }

    private function color(string $text, string $escape): string
    {
        if (!$this->useAnsi) {
            return $text;
        }
        return $escape . $text . self::RESET;
    }
}
