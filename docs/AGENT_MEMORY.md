# Agent Memory

Conversation-approved memory proposals — **never** auto-persist from free text.

## Flow

Candidate extract (deterministic) → `seo_agent_memory_proposals` → UI Save/Edit/Keep/Reject → ingest on approve.

## Classes

- `AgentMemoryCandidateExtractor`
- `AgentMemoryProposalService`
- `AgentKnowledgeOrchestrator::createProposal` / `resolveProposal`

Browser edits allowlisted fields only; scope re-resolved server-side.
