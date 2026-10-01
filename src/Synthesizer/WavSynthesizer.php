<?php

declare(strict_types=1);

namespace EidCloud\AudioInspector\Synthesizer;

use InvalidArgumentException;
use RuntimeException;

/**
 * Pure PHP WAV synthesizer for generating reference audio signals and test fixtures.
 * Supports standard RIFF/WAVE PCM 16-bit integer and 32-bit float formats.
 */
final class WavSynthesizer
{
    /**
     * Generate a sine wave test file.
     *
     * @param string $outputPath Path to write .wav file
     * @param float $frequencyHz Sine frequency in Hz (default 1000 Hz)
     * @param float $durationSec Duration in seconds (default 2.0s)
     * @param float $amplitudeDbfs Peak amplitude in dBFS (e.g. 0.0 = full scale, -23.0 = broadcast tone)
     * @param int $sampleRate Sample rate in Hz (default 44100)
     * @param int $channels Number of audio channels (1 = mono, 2 = stereo)
     * @param int $bitsPerSample Bit depth (16 or 24, default 16)
     */
    public static function generateSineWave(
        string $outputPath,
        float $frequencyHz = 1000.0,
        float $durationSec = 2.0,
        float $amplitudeDbfs = -3.0,
        int $sampleRate = 44100,
        int $channels = 2,
        int $bitsPerSample = 16
    ): string {
        $linearGain = 10 ** ($amplitudeDbfs / 20.0);
        $totalFrames = (int) ($sampleRate * $durationSec);

        $fp = fopen($outputPath, 'wb');
        if (!$fp) {
            throw new RuntimeException("Cannot create output file: {$outputPath}");
        }

        self::writeWavHeader($fp, $totalFrames, $sampleRate, $channels, $bitsPerSample);

        $twoPiF = 2.0 * M_PI * $frequencyHz;
        $maxInt = (1 << ($bitsPerSample - 1)) - 1;

        $buffer = '';
        $bufferFrames = 0;
        $chunkLimit = 4096;

        for ($i = 0; $i < $totalFrames; $i++) {
            $t = $i / $sampleRate;
            $sampleFloat = sin($twoPiF * $t) * $linearGain;
            $sampleFloat = max(-1.0, min(1.0, $sampleFloat));
            $sampleInt = (int) round($sampleFloat * $maxInt);

            if ($bitsPerSample === 16) {
                $packed = pack('s', $sampleInt);
            } elseif ($bitsPerSample === 24) {
                $packed = pack('C3', $sampleInt & 0xFF, ($sampleInt >> 8) & 0xFF, ($sampleInt >> 16) & 0xFF);
            } else {
                throw new InvalidArgumentException("Unsupported bit depth: {$bitsPerSample}");
            }

            for ($ch = 0; $ch < $channels; $ch++) {
                $buffer .= $packed;
            }
            $bufferFrames++;

            if ($bufferFrames >= $chunkLimit) {
                fwrite($fp, $buffer);
                $buffer = '';
                $bufferFrames = 0;
            }
        }

        if ($bufferFrames > 0) {
            fwrite($fp, $buffer);
        }

        fclose($fp);
        return $outputPath;
    }

    /**
     * Generate a digital silence file.
     */
    public static function generateSilence(
        string $outputPath,
        float $durationSec = 2.0,
        int $sampleRate = 44100,
        int $channels = 2
    ): string {
        $totalFrames = (int) ($sampleRate * $durationSec);
        $fp = fopen($outputPath, 'wb');
        if (!$fp) {
            throw new RuntimeException("Cannot create output file: {$outputPath}");
        }

        self::writeWavHeader($fp, $totalFrames, $sampleRate, $channels, 16);

        $silenceBlock = str_repeat(pack('s', 0), $channels * 2048);
        $remainingFrames = $totalFrames;

        while ($remainingFrames > 0) {
            $framesToWrite = min(2048, $remainingFrames);
            fwrite($fp, substr($silenceBlock, 0, $framesToWrite * $channels * 2));
            $remainingFrames -= $framesToWrite;
        }

        fclose($fp);
        return $outputPath;
    }

    /**
     * Generate an intentionally clipped audio file for forensics testing.
     */
    public static function generateClippedTone(
        string $outputPath,
        float $durationSec = 1.0,
        float $overdriveDb = +6.0,
        int $sampleRate = 44100,
        int $channels = 2
    ): string {
        $gain = 10 ** ($overdriveDb / 20.0);
        $totalFrames = (int) ($sampleRate * $durationSec);

        $fp = fopen($outputPath, 'wb');
        if (!$fp) {
            throw new RuntimeException("Cannot create output file: {$outputPath}");
        }

        self::writeWavHeader($fp, $totalFrames, $sampleRate, $channels, 16);

        $twoPiF = 2.0 * M_PI * 440.0;
        $maxInt = 32767;

        $buffer = '';
        $bufferFrames = 0;
        $chunkLimit = 4096;

        for ($i = 0; $i < $totalFrames; $i++) {
            $t = $i / $sampleRate;
            $val = sin($twoPiF * $t) * $gain;
            $clamped = max(-1.0, min(1.0, $val));
            $sampleInt = (int) round($clamped * $maxInt);
            $packed = pack('s', $sampleInt);

            for ($ch = 0; $ch < $channels; $ch++) {
                $buffer .= $packed;
            }
            $bufferFrames++;

            if ($bufferFrames >= $chunkLimit) {
                fwrite($fp, $buffer);
                $buffer = '';
                $bufferFrames = 0;
            }
        }

        if ($bufferFrames > 0) {
            fwrite($fp, $buffer);
        }

        fclose($fp);
        return $outputPath;
    }

    /**
     * Generate dynamic composite audio containing loud tone, quiet tone, and silence interval.
     */
    public static function generateDynamicBroadcastTone(
        string $outputPath,
        float $durationSec = 4.0,
        int $sampleRate = 44100,
        int $channels = 2
    ): string {
        $totalFrames = (int) ($sampleRate * $durationSec);
        $fp = fopen($outputPath, 'wb');
        if (!$fp) {
            throw new RuntimeException("Cannot create output file: {$outputPath}");
        }

        self::writeWavHeader($fp, $totalFrames, $sampleRate, $channels, 16);

        $twoPiF = 2.0 * M_PI * 1000.0;
        $maxInt = 32767;

        $loudGain = 10 ** (-23.0 / 20.0);
        $quietGain = 10 ** (-30.0 / 20.0);

        $buffer = '';
        $bufferFrames = 0;
        $chunkLimit = 4096;

        for ($i = 0; $i < $totalFrames; $i++) {
            $t = $i / $sampleRate;
            if ($t < 2.0) {
                $gain = $loudGain;
                $val = sin($twoPiF * $t) * $gain;
            } elseif ($t < 3.0) {
                $val = 0.0; // Silence window
            } else {
                $gain = $quietGain;
                $val = sin($twoPiF * $t) * $gain;
            }

            $clamped = max(-1.0, min(1.0, $val));
            $sampleInt = (int) round($clamped * $maxInt);
            $packed = pack('s', $sampleInt);

            for ($ch = 0; $ch < $channels; $ch++) {
                $buffer .= $packed;
            }
            $bufferFrames++;

            if ($bufferFrames >= $chunkLimit) {
                fwrite($fp, $buffer);
                $buffer = '';
                $bufferFrames = 0;
            }
        }

        if ($bufferFrames > 0) {
            fwrite($fp, $buffer);
        }

        fclose($fp);
        return $outputPath;
    }

    /**
     * Writes standard 44-byte RIFF/WAVE PCM header.
     *
     * @param resource $fp
     */
    private static function writeWavHeader($fp, int $totalFrames, int $sampleRate, int $channels, int $bitsPerSample): void
    {
        $bytesPerSample = $bitsPerSample / 8;
        $blockAlign = $channels * $bytesPerSample;
        $byteRate = $sampleRate * $blockAlign;
        $dataSize = $totalFrames * $blockAlign;
        $chunkSize = 36 + $dataSize;

        $header = pack(
            'a4Va4a4VvvVVvva4V',
            'RIFF',
            $chunkSize,
            'WAVE',
            'fmt ',
            16,               // Subchunk1Size for PCM
            1,                // AudioFormat (1 = PCM)
            $channels,
            $sampleRate,
            $byteRate,
            $blockAlign,
            $bitsPerSample,
            'data',
            $dataSize
        );

        fwrite($fp, $header);
    }
}
