<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\KeywordIntelligence\Application;

use InvalidArgumentException;

/**
 * Stable public refs — opaque với client; decode nội bộ về numeric ID.
 * Mirror pattern của ContentProjectPublicRef.
 */
final class KeywordIntelligencePublicRef
{
    public static function workspace(int $id): string
    {
        return self::encode('kww', $id);
    }

    public static function keyword(int $id): string
    {
        return self::encode('kw', $id);
    }

    public static function cluster(int $id): string
    {
        return self::encode('kwc', $id);
    }

    public static function topic(int $id): string
    {
        return self::encode('kwt', $id);
    }

    public static function mapVersion(int $id): string
    {
        return self::encode('tmv', $id);
    }

    public static function operation(int $id): string
    {
        return self::encode('kwa', $id);
    }

    public static function relationship(int $id): string
    {
        return self::encode('kwrel', $id);
    }

    public static function articleMapping(int $id): string
    {
        return self::encode('kwam', $id);
    }

    public static function topicClusterLink(int $id): string
    {
        return self::encode('kwtcl', $id);
    }

    public static function decodeWorkspace(string $ref): int
    {
        return self::decode('kww', $ref);
    }

    public static function decodeKeyword(string $ref): int
    {
        return self::decode('kw', $ref);
    }

    public static function decodeCluster(string $ref): int
    {
        return self::decode('kwc', $ref);
    }

    public static function decodeTopic(string $ref): int
    {
        return self::decode('kwt', $ref);
    }

    public static function decodeMapVersion(string $ref): int
    {
        return self::decode('tmv', $ref);
    }

    public static function decodeOperation(string $ref): int
    {
        return self::decode('kwa', $ref);
    }

    /**
     * Public API — reject bare numeric IDs; only opaque kww_* refs.
     */
    public static function resolveWorkspaceIdStrict(string $ref): int
    {
        return self::resolveStrict('kww', $ref, fn (string $r): int => self::decodeWorkspace($r));
    }

    /**
     * Public API — reject bare numeric IDs; only opaque kw_* refs.
     */
    public static function resolveKeywordIdStrict(string $ref): int
    {
        return self::resolveStrict('kw', $ref, fn (string $r): int => self::decodeKeyword($r));
    }

    /**
     * Public API — reject bare numeric IDs; only opaque kwc_* refs.
     */
    public static function resolveClusterIdStrict(string $ref): int
    {
        return self::resolveStrict('kwc', $ref, fn (string $r): int => self::decodeCluster($r));
    }

    /**
     * Public API — reject bare numeric IDs; only opaque kwt_* refs.
     */
    public static function resolveTopicIdStrict(string $ref): int
    {
        return self::resolveStrict('kwt', $ref, fn (string $r): int => self::decodeTopic($r));
    }

    /**
     * Public API — reject bare numeric IDs; only opaque tmv_* refs.
     */
    public static function resolveMapVersionIdStrict(string $ref): int
    {
        return self::resolveStrict('tmv', $ref, fn (string $r): int => self::decodeMapVersion($r));
    }

    /**
     * Public API — reject bare numeric IDs; only opaque kwa_* refs.
     */
    public static function resolveOperationIdStrict(string $ref): int
    {
        return self::resolveStrict('kwa', $ref, fn (string $r): int => self::decodeOperation($r));
    }

    /**
     * @param  callable(string): int  $decoder
     */
    private static function resolveStrict(string $prefix, string $ref, callable $decoder): int
    {
        $ref = trim($ref);
        if ($ref === '' || ctype_digit($ref)) {
            throw new InvalidArgumentException("Ref must be opaque {$prefix}_* identifier.");
        }

        if (! str_starts_with($ref, $prefix.'_')) {
            throw new InvalidArgumentException("Ref must be opaque {$prefix}_* identifier.");
        }

        return $decoder($ref);
    }

    private static function encode(string $prefix, int $id): string
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('Invalid id for public ref.');
        }

        $payload = rtrim(strtr(base64_encode(pack('N', $id)), '+/', '-_'), '=');
        $checksum = substr(hash('xxh3', $prefix.'|'.$id), 0, 6);

        return $prefix.'_'.$payload.'_'.$checksum;
    }

    private static function decode(string $prefix, string $ref): int
    {
        $ref = trim($ref);
        if (! preg_match('/^'.preg_quote($prefix, '/').'_([A-Za-z0-9_-]+)_([a-f0-9]{6})$/', $ref, $m)) {
            throw new InvalidArgumentException("Invalid {$prefix} ref.");
        }

        $raw = base64_decode(strtr($m[1], '-_', '+/'), true);
        if ($raw === false || strlen($raw) < 4) {
            throw new InvalidArgumentException("Invalid {$prefix} ref payload.");
        }

        $unpacked = unpack('Nid', substr($raw, 0, 4));
        $id = (int) ($unpacked['id'] ?? 0);
        if ($id <= 0 || substr(hash('xxh3', $prefix.'|'.$id), 0, 6) !== $m[2]) {
            throw new InvalidArgumentException("Invalid {$prefix} ref checksum.");
        }

        return $id;
    }
}
