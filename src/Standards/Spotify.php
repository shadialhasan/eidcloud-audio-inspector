<?php

declare(strict_types=1);

namespace EidCloud\AudioInspector\Standards;

/**
 * Spotify Loudness Normalization Specification.
 *
 * Target: -14.0 LUFS with ±1.0 LU tolerance.
 * Maximum True Peak: -1.0 dBTP (-2.0 dBTP recommended for louder tracks).
 */
class Spotify extends AbstractStandard
{
    public function getId(): string
    {
        return 'spotify';
    }

    public function getName(): string
    {
        return 'Spotify';
    }

    public function getDescription(): string
    {
        return 'Spotify streaming normalization (-14 LUFS ±1.0 LU, Max True Peak -1.0 dBTP).';
    }

    public function getTargetLufs(): float
    {
        return -14.0;
    }

    public function getLufsTolerance(): float
    {
        return 1.0;
    }

    public function getMaxTruePeak(): float
    {
        return -1.0;
    }

    public function getMaxLra(): ?float
    {
        return null;
    }
}
