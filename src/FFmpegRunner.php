<?php

declare(strict_types=1);

namespace EidCloud\AudioInspector;

use RuntimeException;
use InvalidArgumentException;

/**
 * Executes FFmpeg audio filter pipelines and extracts EBU R128 loudness,
 * True Peak, RMS energy, and silence forensics.
 */
final class FFmpegRunner
{
    private string $ffmpegBinary;

    public function __construct(?string $customBinary = null)
    {
        $this->ffmpegBinary = $customBinary ?? $this->detectFfmpegBinary();
    }

    /**
     * Checks if FFmpeg binary is executable on the current system.
     */
    public function isAvailable(): bool
    {
        if (empty($this->ffmpegBinary)) {
            return false;
        }

        $cmd = escapeshellarg($this->ffmpegBinary) . ' -version 2>&1';
        $output = @shell_exec($cmd);
        return is_string($output) && str_contains($output, 'ffmpeg version');
    }

    /**
     * Auto-detect system FFmpeg binary path.
     */
    private function detectFfmpegBinary(): string
    {
        // Check environment variable
        $env = getenv('FFMPEG_PATH');
        if ($env && file_exists($env)) {
            return $env;
        }

        // Check Windows / Linux PATH
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        $whichCmd = $isWindows ? 'where ffmpeg 2>nul' : 'which ffmpeg 2>/dev/null';

        $output = @shell_exec($whichCmd);
        if (is_string($output) && !empty(trim($output))) {
            $lines = explode("\n", trim($output));
            return trim($lines[0]);
        }

        return 'ffmpeg';
    }

    /**
     * Inspect an audio file using FFmpeg filter graph.
     *
     * @param string $filePath Path to input audio
     * @param float $silenceThresholdDb Silence threshold in dB (default -60.0 dB)
     * @param float $silenceMinDuration Minimum silence duration in seconds (default 0.2s)
     *
     * @throws RuntimeException
     */
    public function inspect(
        string $filePath,
        float $silenceThresholdDb = -60.0,
        float $silenceMinDuration = 0.2
    ): AudioMetrics {
        if (!file_exists($filePath)) {
            throw new InvalidArgumentException("Audio file not found: {$filePath}");
        }

        $absPath = realpath($filePath) ?: $filePath;
        $fileSize = filesize($absPath) ?: 0;
        $ext = strtoupper(pathinfo($absPath, PATHINFO_EXTENSION) ?: 'AUDIO');

        $filterGraph = sprintf(
            'ebur128=peak=true,astats=metadata=1:reset=1,silencedetect=noise=%.1fdB:d=%.2f',
            $silenceThresholdDb,
            $silenceMinDuration
        );

        $command = sprintf(
            '%s -nostats -hide_banner -i %s -af %s -f null - 2>&1',
            escapeshellcmd($this->ffmpegBinary),
            escapeshellarg($absPath),
            escapeshellarg($filterGraph)
        );

        $output = shell_exec($command);
        if (!is_string($output) || empty($output)) {
            throw new RuntimeException("FFmpeg execution returned no output for: {$filePath}");
        }

        return $this->parseOutput($output, $absPath, $fileSize, $ext);
    }

    /**
     * Parse stderr log output of FFmpeg.
     */
    private function parseOutput(string $output, string $filePath, int $fileSize, string $ext): AudioMetrics
    {
        // 1. Duration & Format
        $duration = 0.0;
        if (preg_match('/Duration:\s*(\d+):(\d+):([\d\.]+)/', $output, $m)) {
            $hours = (int) $m[1];
            $mins = (int) $m[2];
            $secs = (float) $m[3];
            $duration = ($hours * 3600) + ($mins * 60) + $secs;
        }

        $sampleRate = 44100;
        $channels = 2;
        $bitDepth = 16;

        if (preg_match('/Audio:\s*([^,]+),\s*(\d+)\s*Hz,\s*([^,]+)/', $output, $m)) {
            $codec = trim($m[1]);
            $sampleRate = (int) $m[2];
            $chStr = strtolower(trim($m[3]));

            if (str_contains($chStr, 'stereo')) {
                $channels = 2;
            } elseif (str_contains($chStr, 'mono')) {
                $channels = 1;
            } elseif (preg_match('/(\d+)\s*channels/', $chStr, $cm)) {
                $channels = (int) $cm[1];
            }

            if (str_contains($codec, 's24') || str_contains($codec, '24-bit')) {
                $bitDepth = 24;
            } elseif (str_contains($codec, 's32') || str_contains($codec, 'flt') || str_contains($codec, '32-bit')) {
                $bitDepth = 32;
            } elseif (str_contains($codec, 's16') || str_contains($codec, '16-bit')) {
                $bitDepth = 16;
            }
        }

        // 2. Integrated LUFS
        $integratedLufs = -70.0;
        if (preg_match('/Integrated loudness:\s+I:\s+([-\d\.]+)\s+LUFS/i', $output, $m)) {
            $integratedLufs = (float) $m[1];
        }

        // 3. Loudness Range (LRA)
        $lra = 0.0;
        if (preg_match('/Loudness range:\s+LRA:\s+([-\d\.]+)\s+LU/i', $output, $m)) {
            $lra = (float) $m[1];
        }

        // 4. True Peak
        $truePeakDbtp = -120.0;
        if (preg_match('/True peak:\s+Peak:\s+([-\d\.]+)\s+(?:dBFS|dBTP)/i', $output, $m)) {
            $truePeakDbtp = (float) $m[1];
        }

        // 5. Astats RMS, Peak & Dynamic Range
        $rmsLevelDbfs = -120.0;
        $samplePeakDbfs = -120.0;
        $flatFactor = 0.0;
        $noiseFloorDb = null;
        $dynamicRangeDb = null;

        if (preg_match_all('/RMS level dB:\s+([-\d\.]+)/i', $output, $m)) {
            $rmsLevelDbfs = (float) end($m[1]);
        }
        if (preg_match_all('/Peak level dB:\s+([-\d\.]+)/i', $output, $m)) {
            $samplePeakDbfs = (float) end($m[1]);
        }
        if (preg_match_all('/Flat factor:\s+([-\d\.]+)/i', $output, $m)) {
            $flatFactor = (float) end($m[1]);
        }
        if (preg_match_all('/Noise floor dB:\s+([-\d\.]+)/i', $output, $m)) {
            $noiseFloorDb = (float) end($m[1]);
        }
        if (preg_match_all('/Dynamic range:\s+([-\d\.]+)/i', $output, $m)) {
            $dynamicRangeDb = (float) end($m[1]);
        }

        // Fallback for sample peak if needed
        if ($samplePeakDbfs <= -119.0 && $truePeakDbtp > -119.0) {
            $samplePeakDbfs = $truePeakDbtp;
        }

        // 6. Silence detection
        $totalSilenceDuration = 0.0;
        if (preg_match_all('/silence_duration:\s+([\d\.]+)/i', $output, $sm)) {
            foreach ($sm[1] as $sd) {
                $totalSilenceDuration += (float) $sd;
            }
        }

        $silenceRatioPercent = ($duration > 0.0)
            ? min(100.0, ($totalSilenceDuration / $duration) * 100.0)
            : 0.0;

        // 7. Clipping estimation
        $clippedSamples = 0;
        if (preg_match_all('/Peak count:\s+([\d\.]+)/i', $output, $m) && $samplePeakDbfs >= -0.01) {
            $clippedSamples = (int) round((float) end($m[1]));
        }
        $totalEstimatedSamples = ($duration > 0 && $sampleRate > 0) ? (int) ($duration * $sampleRate * $channels) : 1;
        $clippedRatioPercent = ($totalEstimatedSamples > 0) ? ($clippedSamples / $totalEstimatedSamples) * 100.0 : 0.0;

        // 8. SNR calculation
        $snrDb = 0.0;
        if ($dynamicRangeDb !== null && $dynamicRangeDb > 0.0) {
            $snrDb = min(120.0, $dynamicRangeDb);
        } elseif ($noiseFloorDb !== null && $noiseFloorDb < $samplePeakDbfs) {
            $snrDb = max(0.0, min(120.0, $samplePeakDbfs - $noiseFloorDb));
        } elseif ($rmsLevelDbfs > -100.0) {
            $snrDb = max(0.0, min(96.0, abs($samplePeakDbfs - (-96.0))));
        }

        return new AudioMetrics(
            filePath: $filePath,
            fileSize: $fileSize,
            format: $ext,
            duration: $duration,
            sampleRate: $sampleRate,
            channels: $channels,
            bitDepth: $bitDepth,
            integratedLufs: round($integratedLufs, 2),
            truePeakDbtp: round($truePeakDbtp, 2),
            samplePeakDbfs: round($samplePeakDbfs, 2),
            loudnessRangeLra: round($lra, 2),
            rmsLevelDbfs: round($rmsLevelDbfs, 2),
            snrDb: round($snrDb, 2),
            silenceRatioPercent: round($silenceRatioPercent, 2),
            silenceDurationSeconds: round($totalSilenceDuration, 3),
            clippedSamples: $clippedSamples,
            clippedRatioPercent: round($clippedRatioPercent, 4),
            analysisMethod: 'ffmpeg',
            rawMetadata: [
                'flat_factor' => $flatFactor,
                'cli_output_length' => strlen($output),
            ]
        );
    }
}
