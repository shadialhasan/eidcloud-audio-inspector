<?php

declare(strict_types=1);

namespace EidCloud\AudioInspector\Standards;

/**
 * Apple Podcasts Audio Delivery Specification.
 *
 * Target: -16.0 LUFS with ±1.0 LU tolerance.
 * Maximum True Peak: -1.0 dBTP.
 */
class ApplePodcasts extends AbstractStandard
{
    public function getId(): string
    {
        return 'apple-podcasts';
    }

    public function getName(): string
    {
        return 'Apple Podcasts';
    }

    public function getDescription(): string
    {
        return 'Apple Podcasts specification for spoken word and podcast audio (-16 LUFS ±1.0 LU, Max True Peak -1.0 dBTP).';
    }

    public function getTargetLufs(): float
    {
        return -16.0;
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
        return null; // Apple does not impose strict LRA limits
    }
}
