> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/AGENT_WORKSPACE.md
> Purpose: implementation history only
# Agent Conversation Summary

`DefaultAgentConversationSummarizer` keeps long chats within budget.

## Includes

Objective, confirmed facts, active refs, decisions, completed/failed executions, open questions, corrections, constraints.

## Excludes

Chain-of-thought, secrets, raw tokens, full result JSON, irrelevant old messages.

## Lifecycle

- Threshold: message count / approx tokens (configurable).
- Version + `summary_until_message_id` on conversation.
- Failure â†’ recent-message window; never blocks chat.
- Cross-conversation / cross-site isolation via conversation scope.
