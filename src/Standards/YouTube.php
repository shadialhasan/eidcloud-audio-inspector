<?php

declare(strict_types=1);

namespace EidCloud\AudioInspector\Standards;

/**
 * YouTube Audio Normalization Specification.
 *
 * Target: -14.0 LUFS (YouTube turns down videos louder than -14 LUFS, tolerance ±1.0 LU).
 * Maximum True Peak: -1.0 dBTP.
 */
class YouTube extends AbstractStandard
{
    public function getId(): string
    {
        return 'youtube';
    }

    public function getName(): string
    {
        return 'YouTube';
    }

    public function getDescription(): string
    {
        return 'YouTube streaming loudness normalization (-14 LUFS ±1.0 LU, Max True Peak -1.0 dBTP).';
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
