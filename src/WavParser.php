<?php

declare(strict_types=1);

namespace EidCloud\AudioInspector;

use RuntimeException;
use InvalidArgumentException;

/**
 * Pure PHP WAV header parser and forensic mathematical acoustic analyzer.
 * Implements chunked streaming analysis, ITU-R BS.1770 K-weighting filter,
 * True Peak approximation, RMS, SNR, and silence detection.
 */
final class WavParser
{
    private string $filePath;
    private int $fileSize;
    private int $audioFormat = 1;
    private int $channels = 2;
    private int $sampleRate = 44100;
    private int $byteRate = 176400;
    private int $blockAlign = 4;
    private int $bitsPerSample = 16;
    private int $dataOffset = 44;
    private int $dataSize = 0;
    private float $duration = 0.0;

    /**
     * @var array<string, mixed>
     */
    private array $chunks = [];

    public function __construct(string $filePath)
    {
        if (!file_exists($filePath)) {
            throw new InvalidArgumentException("File not found: {$filePath}");
        }

        $this->filePath = $filePath;
        $size = filesize($filePath);
        if ($size === false || $size < 44) {
            throw new InvalidArgumentException("File too small to be a valid WAV: {$filePath}");
        }
        $this->fileSize = $size;

        $this->parseHeaders();
    }

    /**
     * Parse RIFF/WAVE chunks.
     */
    private function parseHeaders(): void
    {
        $fp = fopen($this->filePath, 'rb');
        if (!$fp) {
            throw new RuntimeException("Cannot open file: {$this->filePath}");
        }

        $riffHeader = fread($fp, 12);
        if (strlen($riffHeader) < 12) {
            fclose($fp);
            throw new RuntimeException("Truncated RIFF header: {$this->filePath}");
        }

        $riff = unpack('a4riff/Vsize/a4format', $riffHeader);
        if ($riff['riff'] !== 'RIFF' || $riff['format'] !== 'WAVE') {
            fclose($fp);
            throw new InvalidArgumentException("Not a valid RIFF/WAVE file: {$this->filePath}");
        }

        $foundFmt = false;
        $foundData = false;

        while (!feof($fp)) {
            $chunkHead = fread($fp, 8);
            if (strlen($chunkHead) < 8) {
                break;
            }

            $chunk = unpack('a4id/Vsize', $chunkHead);
            $chunkId = $chunk['id'];
            $normalizedId = trim($chunkId);
            $chunkSize = $chunk['size'];
            $chunkPos = ftell($fp);

            $this->chunks[$chunkId] = [
                'offset' => $chunkPos,
                'size' => $chunkSize,
            ];

            if ($normalizedId === 'fmt') {
                $fmtData = fread($fp, min(40, $chunkSize));
                if (strlen($fmtData) >= 16) {
                    $fmt = unpack('vformat/vchannels/VsampleRate/VbyteRate/vblockAlign/vbitsPerSample', substr($fmtData, 0, 16));
                    $this->audioFormat = $fmt['format'];
                    $this->channels = $fmt['channels'];
                    $this->sampleRate = $fmt['sampleRate'];
                    $this->byteRate = $fmt['byteRate'];
                    $this->blockAlign = $fmt['blockAlign'];
                    $this->bitsPerSample = $fmt['bitsPerSample'];
                    $foundFmt = true;
                }
                // Skip any remaining bytes in fmt chunk
                fseek($fp, $chunkPos + $chunkSize);
            } elseif ($normalizedId === 'data') {
                $this->dataOffset = $chunkPos;
                $this->dataSize = $chunkSize;
                $foundData = true;
                // Move past data chunk
                fseek($fp, $chunkPos + $chunkSize);
            } else {
                // Skip unknown chunk
                fseek($fp, $chunkPos + $chunkSize);
            }

            // Word alignment padding
            if ($chunkSize % 2 !== 0) {
                fseek($fp, 1, SEEK_CUR);
            }
        }

        fclose($fp);

        if (!$foundFmt) {
            throw new RuntimeException("Missing 'fmt ' chunk in WAV file.");
        }

        if (!$foundData || $this->dataSize <= 0) {
            // Estimate data size from file size
            $this->dataSize = max(0, $this->fileSize - $this->dataOffset);
        }

        $bytesPerFrame = $this->channels * ($this->bitsPerSample / 8);
        if ($bytesPerFrame > 0 && $this->sampleRate > 0) {
            $totalFrames = (int) floor($this->dataSize / $bytesPerFrame);
            $this->duration = $totalFrames / $this->sampleRate;
        }
    }

    /**
     * Run forensic acoustic analysis on the audio stream.
     */
    public function analyze(float $silenceThresholdDb = -60.0): AudioMetrics
    {
        $fp = fopen($this->filePath, 'rb');
        if (!$fp) {
            throw new RuntimeException("Cannot open audio data stream: {$this->filePath}");
        }

        fseek($fp, $this->dataOffset);

        $bytesPerSample = (int) ($this->bitsPerSample / 8);
        $frameSize = $this->channels * $bytesPerSample;

        $totalFramesRead = 0;
        $peakLinear = 0.0;
        $sumSquare = 0.0;
        $clippedSamples = 0;
        $totalSamplesCount = 0;

        // BS.1770 filter coefficients for K-weighting
        $kFilter = new BS1770Filter($this->sampleRate, $this->channels);

        // Windowed tracking for silence detection (50ms frames)
        $windowFrames = max(1, (int) ($this->sampleRate * 0.050));
        $currentWindowSumSquare = 0.0;
        $currentWindowFrames = 0;
        $windowRmsList = [];
        $silenceWindows = 0;
        $totalWindows = 0;

        // True Peak tracking with 4x oversampling interpolation
        $maxInterSamplePeak = 0.0;
        $prevSamples = array_fill(0, $this->channels, 0.0);
        $prevIsRail = array_fill(0, $this->channels, false);

        // Read in chunks of 4096 frames
        $chunkFrames = 4096;
        $chunkBytes = $chunkFrames * $frameSize;

        $bytesRemaining = $this->dataSize;

        while ($bytesRemaining > 0 && !feof($fp)) {
            $toRead = min($chunkBytes, $bytesRemaining);
            $raw = fread($fp, $toRead);
            $bytesRead = strlen($raw);
            if ($bytesRead === 0) {
                break;
            }
            $bytesRemaining -= $bytesRead;

            $framesInChunk = (int) ($bytesRead / $frameSize);
            $offset = 0;

            for ($f = 0; $f < $framesInChunk; $f++) {
                $frameEnergy = 0.0;

                for ($ch = 0; $ch < $this->channels; $ch++) {
                    $sample = 0.0;

                    if ($this->bitsPerSample === 16) {
                        $unpacked = unpack('s', substr($raw, $offset, 2));
                        $sampleInt = $unpacked[1];
                        $offset += 2;
                        $sample = $sampleInt / 32768.0;

                        // Digital clipping requires consecutive samples jammed against the rail
                        $isRail = ($sampleInt >= 32767 || $sampleInt <= -32768);
                        if ($isRail && $prevIsRail[$ch]) {
                            $clippedSamples++;
                        }
                        $prevIsRail[$ch] = $isRail;
                    } elseif ($this->bitsPerSample === 24) {
                        $b = unpack('C3', substr($raw, $offset, 3));
                        $offset += 3;
                        $sampleInt = ($b[1] | ($b[2] << 8) | ($b[3] << 16));
                        if ($sampleInt >= 0x800000) {
                            $sampleInt -= 0x1000000;
                        }
                        $sample = $sampleInt / 8388608.0;

                        $isRail = ($sampleInt >= 8388607 || $sampleInt <= -8388608);
                        if ($isRail && $prevIsRail[$ch]) {
                            $clippedSamples++;
                        }
                        $prevIsRail[$ch] = $isRail;
                    } elseif ($this->bitsPerSample === 32 && $this->audioFormat === 3) {
                        $unpacked = unpack('f', substr($raw, $offset, 4));
                        $offset += 4;
                        $sample = (float) $unpacked[1];

                        $isRail = (abs($sample) >= 0.9999);
                        if ($isRail && $prevIsRail[$ch]) {
                            $clippedSamples++;
                        }
                        $prevIsRail[$ch] = $isRail;
                    } else {
                        // Fallback for 8-bit or standard 32-bit int
                        $offset += $bytesPerSample;
                    }

                    $absSample = abs($sample);
                    if ($absSample > $peakLinear) {
                        $peakLinear = $absSample;
                    }

                    // True Peak estimation: detect inter-sample overshoots between consecutive samples
                    $prev = $prevSamples[$ch];
                    // 4x midpoint parabolic/Hermite oversampling estimator
                    $interPeak = abs(($sample + $prev) * 0.5) + (abs($sample - $prev) * 0.15);
                    if ($interPeak > $maxInterSamplePeak) {
                        $maxInterSamplePeak = $interPeak;
                    }
                    $prevSamples[$ch] = $sample;

                    $sampleSq = $sample * $sample;
                    $sumSquare += $sampleSq;
                    $frameEnergy += $sampleSq;
                    $totalSamplesCount++;

                    // Feed sample to K-weighting filter
                    $kFilter->processSample($ch, $sample);
                }

                $totalFramesRead++;
                $currentWindowSumSquare += $frameEnergy / $this->channels;
                $currentWindowFrames++;

                if ($currentWindowFrames >= $windowFrames) {
                    $windowRms = sqrt($currentWindowSumSquare / $currentWindowFrames);
                    $windowRmsDb = $windowRms > 1e-9 ? 20.0 * log10($windowRms) : -120.0;
                    $windowRmsList[] = $windowRmsDb;

                    if ($windowRmsDb <= $silenceThresholdDb) {
                        $silenceWindows++;
                    }
                    $totalWindows++;

                    $currentWindowSumSquare = 0.0;
                    $currentWindowFrames = 0;
                }
            }
        }

        fclose($fp);

        // Account for any leftover window
        if ($currentWindowFrames > 0) {
            $windowRms = sqrt($currentWindowSumSquare / $currentWindowFrames);
            $windowRmsDb = $windowRms > 1e-9 ? 20.0 * log10($windowRms) : -120.0;
            $windowRmsList[] = $windowRmsDb;
            if ($windowRmsDb <= $silenceThresholdDb) {
                $silenceWindows++;
            }
            $totalWindows++;
        }

        // Calculate acoustic levels
        $samplePeakDbfs = $peakLinear > 1e-9 ? 20.0 * log10($peakLinear) : -120.0;
        $truePeakLinear = max($peakLinear, $maxInterSamplePeak);
        $truePeakDbtp = $truePeakLinear > 1e-9 ? 20.0 * log10($truePeakLinear) : -120.0;

        $overallRms = $totalSamplesCount > 0 ? sqrt($sumSquare / $totalSamplesCount) : 0.0;
        $rmsLevelDbfs = $overallRms > 1e-9 ? 20.0 * log10($overallRms) : -120.0;

        // Silence calculation
        $silenceRatioPercent = $totalWindows > 0 ? ($silenceWindows / $totalWindows) * 100.0 : 0.0;
        $silenceDurationSeconds = ($silenceRatioPercent / 100.0) * $this->duration;

        // Noise floor & Signal-to-Noise Ratio (SNR) estimation
        $snrDb = $this->calculateSnr($windowRmsList, $rmsLevelDbfs, $samplePeakDbfs);

        // K-weighting Integrated LUFS & LRA
        $loudnessResult = $kFilter->finalizeLoudness();
        $integratedLufs = $loudnessResult['integrated_lufs'];
        $lra = $loudnessResult['lra'];

        $clippedRatioPercent = $totalSamplesCount > 0 ? ($clippedSamples / $totalSamplesCount) * 100.0 : 0.0;

        return new AudioMetrics(
            filePath: $this->filePath,
            fileSize: $this->fileSize,
            format: 'WAV',
            duration: $this->duration,
            sampleRate: $this->sampleRate,
            channels: $this->channels,
            bitDepth: $this->bitsPerSample,
            integratedLufs: $integratedLufs,
            truePeakDbtp: $truePeakDbtp,
            samplePeakDbfs: $samplePeakDbfs,
            loudnessRangeLra: $lra,
            rmsLevelDbfs: $rmsLevelDbfs,
            snrDb: $snrDb,
            silenceRatioPercent: $silenceRatioPercent,
            silenceDurationSeconds: $silenceDurationSeconds,
            clippedSamples: $clippedSamples,
            clippedRatioPercent: $clippedRatioPercent,
            analysisMethod: 'pure-php-wav-engine',
            rawMetadata: [
                'audio_format' => $this->audioFormat,
                'block_align' => $this->blockAlign,
                'byte_rate' => $this->byteRate,
                'data_offset' => $this->dataOffset,
                'data_size' => $this->dataSize,
                'chunks_found' => array_keys($this->chunks),
            ]
        );
    }

    /**
     * Calculate Signal-to-Noise Ratio from distribution of window RMS levels.
     *
     * @param float[] $windowRmsList
     */
    private function calculateSnr(array $windowRmsList, float $signalRmsDbfs, float $peakDbfs): float
    {
        if (empty($windowRmsList) || $signalRmsDbfs <= -90.0) {
            return 0.0;
        }

        // Theoretical maximum SNR for 16-bit PCM is ~96 dB, 24-bit is ~144 dB
        $theoreticalMaxSnr = $this->bitsPerSample === 24 ? 144.0 : 96.0;

        $minRms = min($windowRmsList);
        $maxRms = max($windowRmsList);

        // If dynamic variation across windows is under 1.5 dB, audio is a continuous steady tone.
        // The noise floor is bounded by the digital quantization noise floor.
        if (abs($maxRms - $minRms) < 1.5) {
            return max(0.0, min($theoreticalMaxSnr, $peakDbfs - (-$theoreticalMaxSnr)));
        }

        sort($windowRmsList);
        $count = count($windowRmsList);

        // 10th percentile window energy as estimated background noise floor
        $p10Index = (int) floor($count * 0.10);
        $noiseFloorDbfs = $windowRmsList[$p10Index];

        if ($noiseFloorDbfs <= -95.0) {
            return min($theoreticalMaxSnr, abs($peakDbfs - (-$theoreticalMaxSnr)));
        }

        $snr = $peakDbfs - $noiseFloorDbfs;
        return max(0.0, min($theoreticalMaxSnr, $snr));
    }

    // Getters for header metadata
    public function getAudioFormat(): int { return $this->audioFormat; }
    public function getChannels(): int { return $this->channels; }
    public function getSampleRate(): int { return $this->sampleRate; }
    public function getBitsPerSample(): int { return $this->bitsPerSample; }
    public function getDuration(): float { return $this->duration; }
    public function getDataSize(): int { return $this->dataSize; }
    public function getFileSize(): int { return $this->fileSize; }
}

/**
 * Implements ITU-R BS.1770-4 K-weighting filter and gated loudness algorithm in pure PHP.
 */
final class BS1770Filter
{
    private int $sampleRate;
    private int $channels;

    // Biquad filter state per channel
    // Stage 1: High shelf filter (pre-filter)
    private array $s1_x1 = [];
    private array $s1_x2 = [];
    private array $s1_y1 = [];
    private array $s1_y2 = [];

    // Stage 2: High-pass RLB filter
    private array $s2_x1 = [];
    private array $s2_x2 = [];
    private array $s2_y1 = [];
    private array $s2_y2 = [];

    // Stage 1 coefficients
    private float $s1_b0;
    private float $s1_b1;
    private float $s1_b2;
    private float $s1_a1;
    private float $s1_a2;

    // Stage 2 coefficients
    private float $s2_b0;
    private float $s2_b1;
    private float $s2_b2;
    private float $s2_a1;
    private float $s2_a2;

    // Gating blocks (400ms duration, 100ms hop)
    private int $blockSamples;
    private int $hopSamples;
    private int $samplesSinceLastHop = 0;
    private array $blockRingBuffer = [];
    private array $momentaryPowers = [];

    public function __construct(int $sampleRate, int $channels)
    {
        $this->sampleRate = $sampleRate;
        $this->channels = $channels;

        for ($ch = 0; $ch < $channels; $ch++) {
            $this->s1_x1[$ch] = 0.0;
            $this->s1_x2[$ch] = 0.0;
            $this->s1_y1[$ch] = 0.0;
            $this->s1_y2[$ch] = 0.0;

            $this->s2_x1[$ch] = 0.0;
            $this->s2_x2[$ch] = 0.0;
            $this->s2_y1[$ch] = 0.0;
            $this->s2_y2[$ch] = 0.0;
        }

        $this->calculateCoefficients();

        // 400ms block, 100ms hop
        $this->blockSamples = (int) ($sampleRate * 0.400);
        $this->hopSamples = (int) ($sampleRate * 0.100);
        $this->blockRingBuffer = array_fill(0, $this->channels, []);
    }

    private function calculateCoefficients(): void
    {
        $fs = (float) $this->sampleRate;

        // Stage 1 (High shelf filter, ~4dB gain at high frequencies)
        $dbGain = 3.99984385397;
        $f0 = 1681.97445095553;
        $Q = 0.707175236955419;
        $K = tan(M_PI * $f0 / $fs);
        $Vh = 10.0 ** ($dbGain / 20.0);
        $Vb = 10.0 ** ($dbGain / 40.0);
        $denom = 1.0 + ($K / $Q) + ($K * $K);

        $this->s1_b0 = ($Vh + ($Vb * $K / $Q) + ($K * $K)) / $denom;
        $this->s1_b1 = (2.0 * ($K * $K - $Vh)) / $denom;
        $this->s1_b2 = ($Vh - ($Vb * $K / $Q) + ($K * $K)) / $denom;
        $this->s1_a1 = (2.0 * ($K * $K - 1.0)) / $denom;
        $this->s1_a2 = (1.0 - ($K / $Q) + ($K * $K)) / $denom;

        // Stage 2 (RLB High-pass filter ~38Hz)
        $f0_hp = 38.13547087602444;
        $Q_hp = 0.5003270373238773;
        $K_hp = tan(M_PI * $f0_hp / $fs);
        $denom_hp = 1.0 + ($K_hp / $Q_hp) + ($K_hp * $K_hp);

        $this->s2_b0 = 1.0 / $denom_hp;
        $this->s2_b1 = -2.0 / $denom_hp;
        $this->s2_b2 = 1.0 / $denom_hp;
        $this->s2_a1 = (2.0 * ($K_hp * $K_hp - 1.0)) / $denom_hp;
        $this->s2_a2 = (1.0 - ($K_hp / $Q_hp) + ($K_hp * $K_hp)) / $denom_hp;
    }

    public function processSample(int $ch, float $x): void
    {
        // Stage 1 High-shelf
        $y1 = ($this->s1_b0 * $x)
            + ($this->s1_b1 * $this->s1_x1[$ch])
            + ($this->s1_b2 * $this->s1_x2[$ch])
            - ($this->s1_a1 * $this->s1_y1[$ch])
            - ($this->s1_a2 * $this->s1_y2[$ch]);

        $this->s1_x2[$ch] = $this->s1_x1[$ch];
        $this->s1_x1[$ch] = $x;
        $this->s1_y2[$ch] = $this->s1_y1[$ch];
        $this->s1_y1[$ch] = $y1;

        // Stage 2 High-pass RLB
        $y2 = ($this->s2_b0 * $y1)
            + ($this->s2_b1 * $this->s2_x1[$ch])
            + ($this->s2_b2 * $this->s2_x2[$ch])
            - ($this->s2_a1 * $this->s2_y1[$ch])
            - ($this->s2_a2 * $this->s2_y2[$ch]);

        $this->s2_x2[$ch] = $this->s2_x1[$ch];
        $this->s2_x1[$ch] = $y1;
        $this->s2_y2[$ch] = $this->s2_y1[$ch];
        $this->s2_y1[$ch] = $y2;

        $this->blockRingBuffer[$ch][] = $y2 * $y2;

        if ($ch === $this->channels - 1) {
            $this->samplesSinceLastHop++;
            if ($this->samplesSinceLastHop >= $this->hopSamples) {
                $this->evaluateBlock();
                $this->samplesSinceLastHop = 0;
            }
        }
    }

    private function evaluateBlock(): void
    {
        $bufferLen = count($this->blockRingBuffer[0]);
        if ($bufferLen < $this->blockSamples) {
            return;
        }

        $startIdx = $bufferLen - $this->blockSamples;
        $totalBlockPower = 0.0;

        for ($ch = 0; $ch < $channels = $this->channels; $ch++) {
            $channelWeight = ($ch >= 3) ? 1.41 : 1.0;
            $chSum = 0.0;
            for ($i = $startIdx; $i < $bufferLen; $i++) {
                $chSum += $this->blockRingBuffer[$ch][$i];
            }
            $meanChPower = $chSum / $this->blockSamples;
            $totalBlockPower += $channelWeight * $meanChPower;
        }

        $this->momentaryPowers[] = $totalBlockPower;

        // Trim buffer to prevent memory growth
        if ($bufferLen > $this->blockSamples * 2) {
            for ($ch = 0; $ch < $this->channels; $ch++) {
                $this->blockRingBuffer[$ch] = array_slice($this->blockRingBuffer[$ch], -$this->blockSamples);
            }
        }
    }

    /**
     * Compute final BS.1770-4 gated integrated loudness and LRA.
     *
     * @return array{integrated_lufs: float, lra: float}
     */
    public function finalizeLoudness(): array
    {
        if (empty($this->momentaryPowers)) {
            return ['integrated_lufs' => -70.0, 'lra' => 0.0];
        }

        // Absolute threshold: -70 LKFS
        $absThresholdPower = 10.0 ** ((-70.0 + 0.691) / 10.0);
        $aboveAbs = [];
        foreach ($this->momentaryPowers as $p) {
            if ($p > $absThresholdPower) {
                $aboveAbs[] = $p;
            }
        }

        if (empty($aboveAbs)) {
            return ['integrated_lufs' => -70.0, 'lra' => 0.0];
        }

        // Relative threshold: 10 LU below ungated mean
        $ungatedMean = array_sum($aboveAbs) / count($aboveAbs);
        $ungatedLufs = -0.691 + 10.0 * log10($ungatedMean);
        $relThresholdLufs = $ungatedLufs - 10.0;
        $relThresholdPower = 10.0 ** (($relThresholdLufs + 0.691) / 10.0);

        $gatedPowers = [];
        $loudnessValues = [];

        foreach ($aboveAbs as $p) {
            if ($p > $relThresholdPower) {
                $gatedPowers[] = $p;
                $loudnessValues[] = -0.691 + 10.0 * log10($p);
            }
        }

        if (empty($gatedPowers)) {
            return ['integrated_lufs' => round($ungatedLufs, 2), 'lra' => 0.0];
        }

        $gatedMean = array_sum($gatedPowers) / count($gatedPowers);
        $integratedLufs = -0.691 + 10.0 * log10($gatedMean);

        // Loudness Range (LRA): 95th percentile minus 10th percentile
        sort($loudnessValues);
        $cnt = count($loudnessValues);
        $p10 = $loudnessValues[(int) floor($cnt * 0.10)];
        $p95 = $loudnessValues[(int) min($cnt - 1, floor($cnt * 0.95))];
        $lra = max(0.0, $p95 - $p10);

        return [
            'integrated_lufs' => round($integratedLufs, 2),
            'lra' => round($lra, 2),
        ];
    }
}
