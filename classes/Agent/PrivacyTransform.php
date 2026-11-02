<?php
namespace Agent;

/**
 * Local obfuscate/recover transform for data-exit policy.
 *
 * Obfuscation replaces a sensitive value with an opaque token and retains the
 * mapping only in local process memory. The mapping is inspectable but is
 * never transmitted outbound. Recovery is explicit: an unknown or missing
 * token fails with a defined \RuntimeException rather than silently returning
 * a substitute. This component does not advertise comprehensive DLP.
 */
class PrivacyTransform
{
    private static $map = [];

    /** Obfuscate a value for a category, returning an opaque local token. */
    public static function obfuscate(string $value, string $category): string
    {
        if ($category === '') {
            throw new \InvalidArgumentException('Privacy category is required');
        }
        $token = 'privacy:' . $category . ':' . bin2hex(random_bytes(8));
        self::$map[$category][$token] = $value;
        return $token;
    }

    /**
     * Recover the original value for a token.
     *
     * Throws \RuntimeException for an unknown or missing token; no silent fallback.
     */
    public static function recover(string $token, string $category): string
    {
        if (!isset(self::$map[$category][$token])) {
            throw new \RuntimeException('Unknown or missing privacy token for category: ' . $category);
        }
        return self::$map[$category][$token];
    }

    /** Inspect the local token->value mapping (optionally one category). */
    public static function mapping(?string $category = null): array
    {
        if ($category === null) {
            return self::$map;
        }
        return isset(self::$map[$category]) ? self::$map[$category] : [];
    }

    public static function clear(): void
    {
        self::$map = [];
    }
}
