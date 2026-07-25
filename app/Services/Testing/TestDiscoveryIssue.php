<?php

declare(strict_types=1);

namespace App\Services\Testing;

final readonly class TestDiscoveryIssue
{
    public function __construct(
        public string $file,
        public string $code,
        public string $message,
        public string $fix,
    ) {}

    /**
     * @return array{file: string, code: string, message: string, fix: string}
     */
    public function toArray(): array
    {
        return [
            'file' => $this->file,
            'code' => $this->code,
            'message' => $this->message,
            'fix' => $this->fix,
        ];
    }
}
