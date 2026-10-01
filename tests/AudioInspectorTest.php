<?php

declare(strict_types=1);

namespace EidCloud\AudioInspector\Tests;

use EidCloud\AudioInspector\Inspector;
use EidCloud\AudioInspector\WavParser;
use EidCloud\AudioInspector\Standards\EbuR128;
use EidCloud\AudioInspector\Standards\ApplePodcasts;
use EidCloud\AudioInspector\Standards\YouTube;
use EidCloud\AudioInspector\Standards\Spotify;
use EidCloud\AudioInspector\Standards\StandardRegistry;
use EidCloud\AudioInspector\Synthesizer\WavSynthesizer;
use InvalidArgumentException;
use RuntimeException;

final class AudioInspectorTest
{
    private string $tempDir;

    public function __construct()
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'eidcloud_test_' . bin2hex(random_bytes(4));
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0777, true);
        }
    }

    public function __destruct()
    {
        $this->cleanTempFiles();
    }

    public function cleanTempFiles(): void
    {
        if (is_dir($this->tempDir)) {
            $files = glob($this->tempDir . DIRECTORY_SEPARATOR . '*');
            if (is_array($files)) {
                foreach ($files as $f) {
                    if (is_file($f)) {
                        @unlink($f);
                    }
                }
            }
            @rmdir($this->tempDir);
        }
    }

    /**
     * Test 1: RIFF/WAVE header parsing for Mono and Stereo across sample rates.
     */
    public function testWavHeaderParsingMonoAndStereo(): void
    {
        $monoWav = $this->tempDir . DIRECTORY_SEPARATOR . 'test_mono.wav';
        WavSynthesizer::generateSineWave($monoWav, 440.0, 1.5, -6.0, 48000, 1, 16);

        $parserMono = new WavParser($monoWav);
        assert($parserMono->getChannels() === 1, "Mono channel count should be 1");
        assert($parserMono->getSampleRate() === 48000, "Sample rate should be 48000");
        assert($parserMono->getBitsPerSample() === 16, "Bit depth should be 16");
        assert(abs($parserMono->getDuration() - 1.5) < 0.01, "Duration should be ~1.5s");

        $stereoWav = $this->tempDir . DIRECTORY_SEPARATOR . 'test_stereo.wav';
        WavSynthesizer::generateSineWave($stereoWav, 1000.0, 2.0, -12.0, 44100, 2, 16);

        $parserStereo = new WavParser($stereoWav);
        assert($parserStereo->getChannels() === 2, "Stereo channel count should be 2");
        assert($parserStereo->getSampleRate() === 44100, "Sample rate should be 44100");
        assert(abs($parserStereo->getDuration() - 2.0) < 0.01, "Duration should be ~2.0s");
    }

    /**
     * Test 2: Mathematical acoustic analysis (RMS, Peak, SNR, LUFS) of calibrated sine tone.
     */
    public function testPurePhpAcousticAnalysisAccuracy(): void
    {
        $sineWav = $this->tempDir . DIRECTORY_SEPARATOR . 'test_sine_clean.wav';
        // -1.0 dBFS peak sine wave: Theoretical RMS is -4.01 dBFS
        WavSynthesizer::generateSineWave($sineWav, 1000.0, 1.0, -1.0, 44100, 2, 16);

        $parser = new WavParser($sineWav);
        $metrics = $parser->analyze();

        assert(abs($metrics->samplePeakDbfs - (-1.0)) <= 0.1, "Sample peak should be ~-1.0 dBFS");
        assert(abs($metrics->rmsLevelDbfs - (-4.01)) <= 0.2, "RMS of -1 dBFS sine should be ~-4.01 dBFS");
        assert($metrics->clippedSamples === 0, "Clean sine should have 0 clipped samples");
        assert($metrics->silenceRatioPercent === 0.0, "Active sine wave should have 0% silence");
        assert($metrics->snrDb > 40.0, "Pure tone should have high SNR");
    }

    /**
     * Test 3: Digital silence detection and silence ratio calculations.
     */
    public function testSilenceDetectionAndRatio(): void
    {
        $silenceWav = $this->tempDir . DIRECTORY_SEPARATOR . 'test_silence.wav';
        WavSynthesizer::generateSilence($silenceWav, 2.0, 44100, 2);

        $inspector = new Inspector();
        $metrics = $inspector->inspect($silenceWav, ['force_pure_php' => true]);

        assert($metrics->silenceRatioPercent >= 99.0, "Silence file should have ~100% silence ratio");
        assert(abs($metrics->silenceDurationSeconds - 2.0) <= 0.1, "Silence duration should be ~2.0 seconds");
        assert($metrics->rmsLevelDbfs <= -90.0, "Silence RMS level should be <= -90 dBFS");
    }

    /**
     * Test 4: Hard digital sample clipping detection and forensics.
     */
    public function testDigitalClippingDetection(): void
    {
        $clippedWav = $this->tempDir . DIRECTORY_SEPARATOR . 'test_clipped.wav';
        WavSynthesizer::generateClippedTone($clippedWav, 1.0, +6.0, 44100, 2);

        $inspector = new Inspector();
        $metrics = $inspector->inspect($clippedWav, ['force_pure_php' => true]);

        assert($metrics->clippedSamples > 0, "Clipped tone must have clippedSamples > 0");
        assert($metrics->clippedRatioPercent > 1.0, "Clipped ratio should be > 1%");

        $compliance = $inspector->checkCompliance($metrics, 'ebu-r128');
        assert(!$compliance->isCompliant, "Audio with digital clipping must fail compliance");

        // Verify recommendation exists
        $hasClippingRec = false;
        foreach ($compliance->recommendations as $r) {
            if (str_contains(strtolower($r), 'clip')) {
                $hasClippingRec = true;
                break;
            }
        }
        assert($hasClippingRec, "Must recommend anti-clipping mitigation");
    }

    /**
     * Test 5: EBU R128 broadcast compliance validator (passing and failing).
     */
    public function testEbuR128CompliancePassingAndFailing(): void
    {
        // 1. Compliant Broadcast Tone at -23.0 LUFS
        $compliantWav = $this->tempDir . DIRECTORY_SEPARATOR . 'test_ebu_pass.wav';
        WavSynthesizer::generateSineWave($compliantWav, 1000.0, 2.0, -23.0, 44100, 2, 16);

        $inspector = new Inspector();
        $metrics = $inspector->inspect($compliantWav);
        $resultPass = $inspector->checkCompliance($metrics, 'ebu-r128');

        assert($resultPass->standardId === 'ebu-r128', "Standard ID should be ebu-r128");
        assert($resultPass->targetLufs === -23.0, "EBU R128 target should be -23.0 LUFS");
        assert($resultPass->isCompliant, "Reference -23 LUFS tone should pass EBU R128 compliance");

        // 2. Failing Audio (Overly Loud at -10 LUFS)
        $loudWav = $this->tempDir . DIRECTORY_SEPARATOR . 'test_ebu_loud.wav';
        WavSynthesizer::generateSineWave($loudWav, 1000.0, 2.0, -10.0, 44100, 2, 16);

        $loudMetrics = $inspector->inspect($loudWav);
        $resultFail = $inspector->checkCompliance($loudMetrics, 'ebu-r128');

        assert(!$resultFail->isCompliant, "Loud audio (-10 LUFS) must fail EBU R128 compliance");
        assert($resultFail->lufsDifference < -10.0, "Should detect negative LUFS difference (too loud)");

        $hasGainReduction = false;
        foreach ($resultFail->recommendations as $rec) {
            if (str_contains($rec, 'Lower master output gain')) {
                $hasGainReduction = true;
                break;
            }
        }
        assert($hasGainReduction, "Should recommend lowering master output gain");
    }

    /**
     * Test 6: Apple Podcasts and YouTube streaming specifications.
     */
    public function testStreamingStandardsAppleAndYouTube(): void
    {
        $apple = new ApplePodcasts();
        assert($apple->getTargetLufs() === -16.0, "Apple Podcasts target must be -16.0 LUFS");
        assert($apple->getLufsTolerance() === 1.0, "Apple Podcasts tolerance must be 1.0 LU");
        assert($apple->getMaxTruePeak() === -1.0, "Apple Podcasts max true peak must be -1.0 dBTP");

        $youtube = new YouTube();
        assert($youtube->getTargetLufs() === -14.0, "YouTube target must be -14.0 LUFS");
        assert($youtube->getLufsTolerance() === 1.0, "YouTube tolerance must be 1.0 LU");

        $spotify = new Spotify();
        assert($spotify->getTargetLufs() === -14.0, "Spotify target must be -14.0 LUFS");
    }

    /**
     * Test 7: StandardRegistry lookup and alias resolution.
     */
    public function testStandardRegistryAliases(): void
    {
        $ebu1 = StandardRegistry::get('ebu-r128');
        $ebu2 = StandardRegistry::get('ebu');
        $ebu3 = StandardRegistry::get('r128');
        assert($ebu1 instanceof EbuR128 && $ebu2 instanceof EbuR128 && $ebu3 instanceof EbuR128, "Aliases for EBU R128 must resolve");

        $yt = StandardRegistry::get('yt');
        assert($yt instanceof YouTube, "Alias 'yt' must resolve to YouTube");

        $apple = StandardRegistry::get('podcast');
        assert($apple instanceof ApplePodcasts, "Alias 'podcast' must resolve to ApplePodcasts");

        try {
            StandardRegistry::get('unknown-fake-standard');
            assert(false, "Unknown standard should throw InvalidArgumentException");
        } catch (InvalidArgumentException) {
            assert(true);
        }
    }

    /**
     * Test 8: Serialization of AudioMetrics and ComplianceResult to array / JSON.
     */
    public function testSerializationAndDtoConsistency(): void
    {
        $testWav = $this->tempDir . DIRECTORY_SEPARATOR . 'test_serialize.wav';
        WavSynthesizer::generateSineWave($testWav, 1000.0, 1.0, -16.0, 44100, 2, 16);

        $inspector = new Inspector();
        $metrics = $inspector->inspect($testWav);
        $array = $metrics->toArray();

        assert(isset($array['file_info']['sample_rate_hz']), "Array must contain sample_rate_hz");
        assert(isset($array['acoustic_metrics']['integrated_lufs']), "Array must contain integrated_lufs");
        assert(isset($array['forensics']['analysis_engine']), "Array must contain analysis_engine");

        $compliance = $inspector->checkCompliance($metrics, 'apple-podcasts');
        $compArray = $compliance->toArray();

        assert(isset($compArray['status']), "Compliance array must have status");
        assert(isset($compArray['checks']), "Compliance array must have checks");
        assert(isset($compArray['recommendations']), "Compliance array must have recommendations");
    }

    /**
     * Test 9: CLI Executable execution and JSON formatting test.
     */
    public function testCliExecution(): void
    {
        $testWav = $this->tempDir . DIRECTORY_SEPARATOR . 'cli_test.wav';
        WavSynthesizer::generateSineWave($testWav, 1000.0, 1.0, -23.0, 44100, 2, 16);

        $binPath = realpath(__DIR__ . '/../bin/eidcloud-audio');
        $cmd = sprintf('php %s inspect %s --standard=ebu-r128 --json', escapeshellarg($binPath), escapeshellarg($testWav));

        $output = shell_exec($cmd);
        assert(is_string($output), "CLI execution must produce output string");

        $json = json_decode($output, true);
        assert(is_array($json), "CLI --json must output valid JSON");
        assert(isset($json['generator']) && $json['generator'] === 'eidcloud-audio-inspector', "JSON generator must match");
        assert(isset($json['metrics']['acoustic_metrics']['integrated_lufs']), "JSON metrics must have integrated_lufs");
        assert(isset($json['compliance']['is_compliant']), "JSON compliance must have is_compliant");
    }
}
