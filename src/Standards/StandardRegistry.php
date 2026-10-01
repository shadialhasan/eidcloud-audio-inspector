<?php

declare(strict_types=1);

namespace EidCloud\AudioInspector\Standards;

use InvalidArgumentException;

/**
 * Registry of supported broadcast and streaming loudness standards.
 */
final class StandardRegistry
{
    /**
     * @var array<string, StandardInterface>
     */
    private static array $standards = [];

    /**
     * Initialize standard definitions.
     */
    private static function init(): void
    {
        if (!empty(self::$standards)) {
            return;
        }

        $items = [
            new EbuR128(),
            new ApplePodcasts(),
            new YouTube(),
            new Spotify(),
        ];

        foreach ($items as $item) {
            self::$standards[$item->getId()] = $item;
        }
    }

    /**
     * Retrieve standard instance by ID or common alias.
     *
     * @throws InvalidArgumentException
     */
    public static function get(string $nameOrId): StandardInterface
    {
        self::init();

        $key = strtolower(trim($nameOrId));
        $aliases = [
            'ebu' => 'ebu-r128',
            'ebur128' => 'ebu-r128',
            'r128' => 'ebu-r128',
            'podcast' => 'apple-podcasts',
            'apple' => 'apple-podcasts',
            'podcasts' => 'apple-podcasts',
            'yt' => 'youtube',
            'sp' => 'spotify',
        ];

        $resolved = $aliases[$key] ?? $key;

        if (!isset(self::$standards[$resolved])) {
            $available = implode(', ', array_keys(self::$standards));
            throw new InvalidArgumentException(
                "Unknown standard '{$nameOrId}'. Supported standards: {$available}"
            );
        }

        return self::$standards[$resolved];
    }

    /**
     * Get all registered standards.
     *
     * @return array<string, StandardInterface>
     */
    public static function all(): array
    {
        self::init();
        return self::$standards;
    }

    /**
     * Register or override a custom standard.
     */
    public static function register(StandardInterface $standard): void
    {
        self::init();
        self::$standards[$standard->getId()] = $standard;
    }
}
