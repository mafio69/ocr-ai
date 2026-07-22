#!/bin/bash

# Secret guard: detect hardcoded secrets in staged files
# Patterns: API keys, tokens, passwords, private keys

set -e

PATTERNS=(
    'AKIA[0-9A-Z]{16}'                          # AWS Access Key
    'password\s*=\s*["\x27][^"\x27]{8,}'        # password = "..."
    'api[_-]?key\s*=\s*["\x27][^"\x27]{16,}'    # api_key = "..."
    'token\s*=\s*["\x27][^"\x27]{16,}'          # token = "..."
    'secret\s*=\s*["\x27][^"\x27]{16,}'         # secret = "..."
    '-----BEGIN (RSA |EC |DSA )?PRIVATE KEY-----' # Private keys
    'ghp_[a-zA-Z0-9]{36}'                       # GitHub personal token
    'sk-[a-zA-Z0-9]{32,}'                       # OpenAI/Stripe secret key
    'xox[baprs]-[a-zA-Z0-9-]+'                 # Slack token
)

# Get staged PHP files (exclude vendor, node_modules, .git)
FILES=$(git diff --cached --name-only --diff-filter=ACM | grep -E '\.(php|json|yml|yaml|env)$' | grep -v -E '(vendor|node_modules|^\.git)' || true)

if [ -z "$FILES" ]; then
    echo "✅ No PHP/config files staged"
    exit 0
fi

FOUND=0

for pattern in "${PATTERNS[@]}"; do
    MATCHES=$(echo "$FILES" | xargs grep -lEi "$pattern" 2>/dev/null || true)
    if [ -n "$MATCHES" ]; then
        echo "❌ Potential secret found (pattern: $pattern):"
        echo "$MATCHES" | sed 's/^/   /'
        FOUND=1
    fi
done

if [ $FOUND -eq 1 ]; then
    echo ""
    echo "💡 If this is a false positive, use: git commit --no-verify"
    echo "💡 Move secrets to .env (see .env.example)"
    exit 1
fi

echo "✅ No hardcoded secrets detected"
exit 0
