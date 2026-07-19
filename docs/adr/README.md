# Architecture Decision Records (ADR)

This directory contains Architecture Decision Records for the OVH OCR project.

## What is an ADR?

An ADR captures a single architectural decision, its context, and consequences. It documents **why** we made a choice, not just **what** we chose.

## ADR Index

- [ADR-001: Three-Tier Model Strategy with Fallback](001-model-strategy.md) - Why we use lite/medium/premium tiers with automatic fallback
- [ADR-002: Automated Code Quality with GrumPHP](002-automated-quality-gates.md) - Why we use php-cs-fixer + phpstan + GrumPHP for pre-commit checks

## Format

Each ADR follows this structure:
1. **Status** - Proposed, Accepted, Deprecated, Superseded
2. **Context** - What is the problem or situation?
3. **Decision** - What did we decide to do?
4. **Consequences** - What are the trade-offs (positive and negative)?
5. **Alternatives Considered** - What else did we consider?
6. **References** - Links to related code, docs, or discussions

## When to Write an ADR

Write an ADR when:
- Making a significant architectural choice
- Choosing between multiple approaches
- Introducing a new tool or framework
- Changing an existing pattern

Don't write an ADR for:
- Simple bug fixes
- Minor refactoring
- Implementation details

## Resources

- [Michael Nygard's ADR Blog](https://cognitect.com/blog/2011/11/15/documenting-architecture-decisions)
- [ADR GitHub Tool](https://github.com/npryce/adr-tools)
