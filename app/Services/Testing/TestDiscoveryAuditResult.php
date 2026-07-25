<?php

declare(strict_types=1);

namespace App\Services\Testing;

final class TestDiscoveryAuditResult
{
    /**
     * @param  list<TestDiscoveryIssue>  $issues
     * @param  list<string>  $discoveredClasses
     * @param  list<string>  $scannedTestFiles
     * @param  list<string>  $supportFiles
     * @param  list<string>  $configuredDirectories
     */
    public function __construct(
        public readonly array $issues = [],
        public readonly array $discoveredClasses = [],
        public readonly array $scannedTestFiles = [],
        public readonly array $supportFiles = [],
        public readonly array $configuredDirectories = [],
        public readonly bool $pestAvailable = false,
        public readonly ?string $phpunitListError = null,
    ) {}

    public function ok(): bool
    {
        return $this->issues === [] && $this->phpunitListError === null;
    }

    public function issueCount(): int
    {
        return count($this->issues);
    }
}
