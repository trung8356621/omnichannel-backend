<?php

declare(strict_types=1);

namespace App\Core\Automation;

/**
 * Minimal condition engine — boolean AND of named predicates.
 * Business predicates register from addons via closures.
 */
final class ConditionEngine
{
    /** @var array<string, callable(array<string, mixed>): bool> */
    private array $predicates = [];

    public function register(string $name, callable $predicate): void
    {
        $this->predicates[$name] = $predicate;
    }

    public function has(string $name): bool
    {
        return isset($this->predicates[$name]);
    }

    /**
     * @param  list<array{name: string, params?: array<string, mixed>}>  $conditions
     * @param  array<string, mixed>  $context
     */
    public function evaluate(array $conditions, array $context = []): bool
    {
        if ($conditions === []) {
            return true;
        }

        foreach ($conditions as $condition) {
            $name = (string) ($condition['name'] ?? '');
            if ($name === '' || ! isset($this->predicates[$name])) {
                return false;
            }

            $params = is_array($condition['params'] ?? null) ? $condition['params'] : [];
            if (! ($this->predicates[$name])(array_merge($context, $params))) {
                return false;
            }
        }

        return true;
    }
}
