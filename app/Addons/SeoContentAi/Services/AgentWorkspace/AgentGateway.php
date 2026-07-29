<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\AgentWorkspace;

use App\Addons\SeoContentAi\Services\ContentProject\Agent\AgentCapabilityResult;
use App\Addons\SeoContentAi\Services\ContentProject\Agent\AgentExecutionContext;
use App\Addons\SeoContentAi\Services\ContentProject\Agent\ContentProjectAgentGateway;

/**
 * Shared application facade for MCP transport and Web Agent UI.
 * Does not duplicate gateway logic — delegates to ContentProjectAgentGateway.
 */
final class AgentGateway
{
    public function __construct(
        private readonly ContentProjectAgentGateway $gateway,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function execute(AgentExecutionContext $context, string $capability, array $input = []): AgentCapabilityResult
    {
        return $this->gateway->execute($context, $capability, $input);
    }

    /**
     * @return list<string>
     */
    public function readCapabilities(): array
    {
        return ContentProjectAgentGateway::READ_CAPABILITIES;
    }
}
