<?php

declare(strict_types=1);

namespace EidCloud\AudioInspector;

use EidCloud\AudioInspector\Standards\StandardInterface;
use EidCloud\AudioInspector\Standards\StandardRegistry;
use InvalidArgumentException;
use RuntimeException;

/**
 * Primary Audio Forensics and Broadcast Compliance Inspector.
 * Coordinates FFmpeg filter analysis with pure PHP mathematical fallback.
 */
final class Inspector
{
    private FFmpegRunner $ffmpegRunner;

    public function __construct(?string $customFfmpegPath = null)
    {
        $this->ffmpegRunner = new FFmpegRunner($customFfmpegPath);
    }

    /**
     * Check if FFmpeg is installed and accessible.
     */
    public function isFfmpegAvailable(): bool
    {
        return $this->ffmpegRunner->isAvailable();
    }

    /**
     * Inspect an audio file and measure acoustic forensics.
     *
     * @param string $filePath Path to target audio file
     * @param array{
     *     force_pure_php?: bool,
     *     silence_threshold_db?: float,
     *     silence_min_duration?: float
     * } $options
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function inspect(string $filePath, array $options = []): AudioMetrics
    {
        if (!file_exists($filePath)) {
            throw new InvalidArgumentException("Audio file not found: {$filePath}");
        }

        $forcePurePhp = $options['force_pure_php'] ?? false;
        $silenceThresholdDb = $options['silence_threshold_db'] ?? -60.0;
        $silenceMinDuration = $options['silence_min_duration'] ?? 0.2;

        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $isWav = ($ext === 'wav' || $ext === 'wave');

        // Case 1: Pure PHP explicitly requested
        if ($forcePurePhp) {
            if (!$isWav) {
                throw new InvalidArgumentException(
                    "Pure PHP fallback currently supports uncompressed RIFF/WAVE (.wav) files. " .
                    "For format '.{$ext}', please allow FFmpeg integration or convert to WAV."
                );
            }
            $parser = new WavParser($filePath);
            return $parser->analyze($silenceThresholdDb);
        }

        // Case 2: FFmpeg is available
        if ($this->ffmpegRunner->isAvailable()) {
            $metrics = $this->ffmpegRunner->inspect($filePath, $silenceThresholdDb, $silenceMinDuration);

            // If it's a WAV file, we can augment with bit-exact digital clipping detection from WavParser
            if ($isWav && $metrics->clippedSamples === 0) {
                try {
                    $wavParser = new WavParser($filePath);
                    $wavMetrics = $wavParser->analyze($silenceThresholdDb);
                    if ($wavMetrics->clippedSamples > 0) {
                        return new AudioMetrics(
                            filePath: $metrics->filePath,
                            fileSize: $metrics->fileSize,
                            format: $metrics->format,
                            duration: $metrics->duration,
                            sampleRate: $wavMetrics->sampleRate,
                            channels: $wavMetrics->channels,
                            bitDepth: $wavMetrics->bitDepth,
                            integratedLufs: $metrics->integratedLufs,
                            truePeakDbtp: $metrics->truePeakDbtp,
                            samplePeakDbfs: $metrics->samplePeakDbfs,
                            loudnessRangeLra: $metrics->loudnessRangeLra,
                            rmsLevelDbfs: $metrics->rmsLevelDbfs,
                            snrDb: $metrics->snrDb > 0.0 ? $metrics->snrDb : $wavMetrics->snrDb,
                            silenceRatioPercent: $metrics->silenceRatioPercent,
                            silenceDurationSeconds: $metrics->silenceDurationSeconds,
                            clippedSamples: $wavMetrics->clippedSamples,
                            clippedRatioPercent: $wavMetrics->clippedRatioPercent,
                            analysisMethod: 'ffmpeg+wav-augmented',
                            rawMetadata: array_merge($metrics->rawMetadata, $wavMetrics->rawMetadata)
                        );
                    }
                } catch (\Throwable) {
                    // Fall back to pure ffmpeg metrics
                }
            }

            return $metrics;
        }

        // Case 3: FFmpeg is NOT available, fallback to pure PHP if WAV
        if ($isWav) {
            $parser = new WavParser($filePath);
            return $parser->analyze($silenceThresholdDb);
        }

        // Case 4: Non-WAV and no FFmpeg
        throw new RuntimeException(
            "FFmpeg is not installed or not found in PATH, and the target file format '.{$ext}' " .
            "cannot be parsed by the zero-dependency pure PHP WAV engine. " .
            "Please install FFmpeg or supply an uncompressed WAV file."
        );
    }

    /**
     * Check measured metrics against a broadcast or streaming specification.
     *
     * @param AudioMetrics $metrics Measured audio metrics
     * @param string|StandardInterface $standard Standard ID, alias, or instance (default: 'ebu-r128')
     */
    public function checkCompliance(AudioMetrics $metrics, string|StandardInterface $standard = 'ebu-r128'): ComplianceResult
    {
        $stdInstance = is_string($standard) ? StandardRegistry::get($standard) : $standard;
        return $stdInstance->validate($metrics);
    }
}
