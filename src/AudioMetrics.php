<?php

declare(strict_types=1);

namespace EidCloud\AudioInspector;

/**
 * Value Object representing forensic acoustic measurements of an audio file.
 */
final class AudioMetrics
{
    public function __construct(
        public readonly string $filePath,
        public readonly int $fileSize,
        public readonly string $format,
        public readonly float $duration,
        public readonly int $sampleRate,
        public readonly int $channels,
        public readonly int $bitDepth,
        public readonly float $integratedLufs,
        public readonly float $truePeakDbtp,
        public readonly float $samplePeakDbfs,
        public readonly float $loudnessRangeLra,
        public readonly float $rmsLevelDbfs,
        public readonly float $snrDb,
        public readonly float $silenceRatioPercent,
        public readonly float $silenceDurationSeconds,
        public readonly int $clippedSamples,
        public readonly float $clippedRatioPercent,
        public readonly string $analysisMethod,
        public readonly array $rawMetadata = []
    ) {}

    /**
     * Convert metrics to associative array for JSON serialization and reporting.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'file_info' => [
                'path' => $this->filePath,
                'basename' => basename($this->filePath),
                'size_bytes' => $this->fileSize,
                'size_formatted' => $this->formatFileSize($this->fileSize),
                'format' => $this->format,
                'duration_seconds' => round($this->duration, 3),
                'duration_formatted' => $this->formatDuration($this->duration),
                'sample_rate_hz' => $this->sampleRate,
                'channels' => $this->channels,
                'channel_layout' => $this->channels === 1 ? 'Mono' : ($this->channels === 2 ? 'Stereo' : "{$this->channels}ch"),
                'bit_depth' => $this->bitDepth,
            ],
            'acoustic_metrics' => [
                'integrated_lufs' => round($this->integratedLufs, 2),
                'true_peak_dbtp' => round($this->truePeakDbtp, 2),
                'sample_peak_dbfs' => round($this->samplePeakDbfs, 2),
                'loudness_range_lra' => round($this->loudnessRangeLra, 2),
                'rms_level_dbfs' => round($this->rmsLevelDbfs, 2),
                'snr_db' => round($this->snrDb, 2),
                'silence_ratio_percent' => round($this->silenceRatioPercent, 2),
                'silence_duration_seconds' => round($this->silenceDurationSeconds, 3),
                'clipped_samples' => $this->clippedSamples,
                'clipped_ratio_percent' => round($this->clippedRatioPercent, 4),
            ],
            'forensics' => [
                'analysis_engine' => $this->analysisMethod,
                'has_clipping' => $this->clippedSamples > 0,
                'is_mostly_silent' => $this->silenceRatioPercent > 80.0,
                'dynamic_range_db' => round(abs($this->samplePeakDbfs - $this->rmsLevelDbfs), 2),
            ],
            'metadata' => $this->rawMetadata,
        ];
    }

    private function formatFileSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return sprintf('%.2f MB', $bytes / 1048576);
        }
        if ($bytes >= 1024) {
            return sprintf('%.2f KB', $bytes / 1024);
        }
        return $bytes . ' B';
    }

    private function formatDuration(float $seconds): string
    {
        $mins = (int) floor($seconds / 60);
        $secs = $seconds - ($mins * 60);
        return sprintf('%02d:%05.2f', $mins, $secs);
    }
}
