<?php

declare(strict_types=1);

namespace EidCloud\AudioInspector\Standards;

/**
 * European Broadcasting Union (EBU) Recommendation R128.
 *
 * Defines audio loudness normalisation and permitted maximum level of audio signals.
 * Target: -23.0 LUFS with ±0.5 LU tolerance for broadcast programmes.
 * Maximum permitted True Peak level: -1.0 dBTP.
 * Recommended Maximum Loudness Range (LRA): 18.0 LU.
 */
class EbuR128 extends AbstractStandard
{
    public function getId(): string
    {
        return 'ebu-r128';
    }

    public function getName(): string
    {
        return 'EBU R128 (European Broadcast)';
    }

    public function getDescription(): string
    {
        return 'European Broadcasting Union standard for television and radio distribution (-23 LUFS ±0.5 LU, Max True Peak -1.0 dBTP).';
    }

    public function getTargetLufs(): float
    {
        return -23.0;
    }

    public function getLufsTolerance(): float
    {
        return 0.5;
    }

    public function getMaxTruePeak(): float
    {
        return -1.0;
    }

    public function getMaxLra(): ?float
    {
        return 18.0;
    }
}
