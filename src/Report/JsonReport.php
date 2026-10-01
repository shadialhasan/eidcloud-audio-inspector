<?php

declare(strict_types=1);

namespace EidCloud\AudioInspector\Report;

use EidCloud\AudioInspector\AudioMetrics;
use EidCloud\AudioInspector\ComplianceResult;

/**
 * Formats audio metrics and compliance results into machine-readable JSON.
 */
final class JsonReport
{
    /**
     * Render full JSON representation.
     */
    public function render(AudioMetrics $metrics, ?ComplianceResult $compliance = null): string
    {
        $payload = [
            'generator' => 'eidcloud-audio-inspector',
            'version' => '1.0.0',
            'timestamp' => date('c'),
            'metrics' => $metrics->toArray(),
        ];

        if ($compliance !== null) {
            $payload['compliance'] = $compliance->toArray();
        }

        return (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
