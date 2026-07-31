> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/AGENT_WORKSPACE.md
> Purpose: implementation history only
# Agent Memory

Conversation-approved memory proposals â€” **never** auto-persist from free text.

## Flow

Candidate extract (deterministic) â†’ `seo_agent_memory_proposals` â†’ UI Save/Edit/Keep/Reject â†’ ingest on approve.

## Classes

- `AgentMemoryCandidateExtractor`
- `AgentMemoryProposalService`
- `AgentKnowledgeOrchestrator::createProposal` / `resolveProposal`

Browser edits allowlisted fields only; scope re-resolved server-side.
