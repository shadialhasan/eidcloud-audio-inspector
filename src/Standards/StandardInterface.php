<?php

declare(strict_types=1);

namespace EidCloud\AudioInspector\Standards;

use EidCloud\AudioInspector\AudioMetrics;
use EidCloud\AudioInspector\ComplianceResult;

/**
 * Contract for audio broadcast and streaming delivery standards.
 */
interface StandardInterface
{
    /**
     * Unique identifier slug (e.g. 'ebu-r128', 'apple-podcasts', 'youtube').
     */
    public function getId(): string;

    /**
     * Human-readable standard title.
     */
    public function getName(): string;

    /**
     * Brief specification description.
     */
    public function getDescription(): string;

    /**
     * Target Integrated Loudness in LUFS.
     */
    public function getTargetLufs(): float;

    /**
     * Permitted Loudness tolerance window (± LU).
     */
    public function getLufsTolerance(): float;

    /**
     * Maximum allowed True Peak in dBTP.
     */
    public function getMaxTruePeak(): float;

    /**
     * Maximum recommended Loudness Range (LRA) in LU, or null if unrestricted.
     */
    public function getMaxLra(): ?float;

    /**
     * Evaluates measured audio metrics against the specification.
     */
    public function validate(AudioMetrics $metrics): ComplianceResult;
}
