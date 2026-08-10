<?php

declare(strict_types=1);

namespace App\Core\Api;

/**
 * Stable API response envelope — transport belongs to Core; business payloads to addons.
 */
final class ApiEnvelope
{
    /**
     * @param  array<string, mixed>|null  $data
     * @param  array<string, mixed>  $meta
     * @param  list<array{code: string, message: string}>  $errors
     */
    public function __construct(
        public readonly bool $ok,
        public readonly ?array $data = null,
        public readonly array $meta = [],
        public readonly array $errors = [],
        public readonly string $apiVersion = 'v1',
    ) {}

    /**
     * @param  array<string, mixed>|null  $data
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public static function success(?array $data = null, array $meta = [], string $apiVersion = 'v1'): array
    {
        return (new self(true, $data, $meta, [], $apiVersion))->toArray();
    }

    /**
     * @param  list<array{code: string, message: string}>  $errors
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public static function failure(array $errors, array $meta = [], string $apiVersion = 'v1'): array
    {
        return (new self(false, null, $meta, $errors, $apiVersion))->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'data' => $this->data,
            'meta' => array_merge([
                'api_version' => $this->apiVersion,
            ], $this->meta),
            'errors' => $this->errors,
        ];
    }
}
