<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Automation\BusinessHook\Registry;

use App\Addons\SeoContentAi\Automation\BusinessHook\Data\BusinessEventDefinition;
use App\Addons\SeoContentAi\Automation\BusinessHook\Enums\BusinessHookErrorCode;
use App\Addons\SeoContentAi\Automation\Exceptions\AutomationException;
use InvalidArgumentException;

final class BusinessEventRegistry
{
    /** @var array<string, BusinessEventDefinition> */
    private array $events = [];

    public function register(BusinessEventDefinition $definition): void
    {
        if (isset($this->events[$definition->name])) {
            throw new InvalidArgumentException("Business event [{$definition->name}] already registered.");
        }

        $this->events[$definition->name] = $definition;
    }

    public function has(string $name): bool
    {
        return isset($this->events[$name]);
    }

    public function get(string $name): BusinessEventDefinition
    {
        if (! isset($this->events[$name])) {
            throw new AutomationException(
                BusinessHookErrorCode::EventNotRegistered->value,
                "Business event [{$name}] is not registered.",
            );
        }

        return $this->events[$name];
    }

    /**
     * @return array<string, BusinessEventDefinition>
     */
    public function all(): array
    {
        return $this->events;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    public function validatePayload(string $name, array $payload): array
    {
        $definition = $this->get($name);
        $errors = [];

        foreach ($definition->payloadSchema as $field => $rule) {
            $required = (bool) ($rule['required'] ?? false);
            $exists = array_key_exists($field, $payload);
            if ($required && (! $exists || $payload[$field] === null || $payload[$field] === '')) {
                $errors[] = "Missing required payload field [{$field}].";
            }
        }

        return $errors;
    }
}
