# ADR-002: Automated Code Quality with GrumPHP

## Status
Accepted

## Context
The project needs automated code quality checks to enforce coding standards, detect security vulnerabilities, and catch bugs before they reach production. Manual code review is not enough - we need automated tools that run on every commit.

## Decision
Implement a three-layer quality gate:

1. **php-cs-fixer** - Enforces PSR-12 coding standards automatically
2. **phpstan** - Static analysis at level 5 (catches type errors, dead code)
3. **GrumPHP** - Orchestrates all checks as git pre-commit hooks

Additional checks in CI:
- `secret-guard.sh` - Detects hardcoded secrets (API keys, tokens, passwords)
- `check-trivial-asserts.sh` - Prevents trivial assertions in tests
- `composer audit` - Checks for security vulnerabilities in dependencies

## Consequences

### Positive
- **Consistent code style**: All code follows PSR-12, no manual formatting debates
- **Early bug detection**: phpstan catches type errors before runtime
- **Security**: secret-guard prevents accidental commits of API keys
- **Zero config**: GrumPHP runs automatically on every commit
- **CI/CD ready**: Same checks run in GitHub Actions

### Negative
- **Slower commits**: Pre-commit checks add 2-5 seconds to each commit
- **Learning curve**: Developers must understand phpstan errors and fix them
- **False positives**: phpstan baseline needed for legacy code patterns

## Alternatives Considered
1. **Manual code review only**: Too slow, inconsistent, error-prone
2. **Separate tools without orchestration**: Developers forget to run them
3. **Lower phpstan level (0-3)**: Misses important bugs
4. **No secret detection**: Risk of committing API keys

## Configuration
- `grumphp.yml` - Task orchestration
- `.php-cs-fixer.dist.php` - PSR-12 + project-specific rules
- `phpstan.dist.neon` - Level 5 analysis
- `phpstan-baseline.neon` - Known issues in legacy code
- `.github/workflows/ci.yml` - CI/CD pipeline

## References
- `docs/grumphp-runbook.md` - Setup and troubleshooting guide
- `.github/workflows/ci.yml` - CI pipeline configuration
- `scripts/secret-guard.sh` - Secret detection patterns
