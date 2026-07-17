#!/usr/bin/env bash
# Detects trivial assertions in tests (always pass, check nothing).
# Usage: bash scripts/check-trivial-asserts.sh [dir]   (default: tests)
# Returns 1 if it finds anything - suitable for CI / pre-commit. Requires grep -P (GNU grep).
set -uo pipefail

dir="${1:-tests}"

if [ ! -d "$dir" ]; then
  echo "Brak katalogu: $dir" >&2
  exit 2
fi

# Kazdy wzorzec osobno (backreference \1 dziala tylko w obrebie jednego wzorca).
patterns=(
  'assertTrue\s*\(\s*true\s*\)'
  'assertFalse\s*\(\s*false\s*\)'
  'assertNull\s*\(\s*null\s*\)'
  'assertNotNull\s*\(\s*true\s*\)'
  'assertEmpty\s*\(\s*(\[\s*\]|array\s*\(\s*\)|""|'"''"'|0)\s*\)'
  # same literal on both sides: assertSame(true, true), assertEquals(1, 1)
  'assert(?:Same|Equals)\s*\(\s*(true|false|null|[0-9]+)\s*,\s*\1\s*\)'
  # same variable on both sides: assertEquals($x, $x)
  'assert(?:Same|Equals)\s*\(\s*(\$[A-Za-z_][A-Za-z0-9_]*)\s*,\s*\1\s*\)'
  # Playwright / JS
  'expect\s*\(\s*true\s*\)\s*\.\s*(?:toBe|toBeTruthy|toEqual)\s*\(\s*(?:true)?\s*\)'
  'expect\s*\(\s*1\s*\)\s*\.\s*toBe\s*\(\s*1\s*\)'
)

hits=""
for p in "${patterns[@]}"; do
  found=$(grep -rPnI --include='*.php' --include='*.js' --include='*.ts' "$p" "$dir" || true)
  [ -n "$found" ] && hits+="$found"$'\n'
done

# Separately: skipped/incomplete tests (markTestSkipped/markTestIncomplete). Blocked by
# default - often a sign of "test isn't ready, but marked as if it passes". A legitimate
# conditional skip (e.g. a PHP extension unavailable in a given environment, like WebP
# in GD) can be deliberately excluded from the block with a "trivial-check-allow: <reason>"
# comment on the line DIRECTLY ABOVE the markTestSkipped/Incomplete call - without that
# marker every skip blocks, so the exception is always explicit and justified in the code.
skipped_raw=$(grep -rEn --include='*.php' 'markTest(Skipped|Incomplete)\s*\(' "$dir" || true)
skipped=""
if [ -n "$skipped_raw" ]; then
  while IFS=: read -r file line rest; do
    [ -z "${file:-}" ] && continue
    prev_line_no=$((line - 1))
    prev_line=$(sed -n "${prev_line_no}p" "$file" 2>/dev/null || true)
    if ! printf '%s' "$prev_line" | grep -q 'trivial-check-allow'; then
      skipped+="${file}:${line}:${rest}"$'\n'
    fi
  done <<< "$skipped_raw"
fi

hits=$(printf '%s' "$hits" | sed '/^$/d' | sort -u)
skipped=$(printf '%s' "$skipped" | sed '/^$/d')

if [ -z "$hits" ] && [ -z "$skipped" ]; then
  echo "OK: no trivial assertions in $dir"
  exit 0
fi

if [ -n "$hits" ]; then
  echo "FOUND trivial assertions:" >&2
  echo "$hits" | sed 's/^/  /' >&2
fi

if [ -n "$skipped" ]; then
  echo "WARNING - skipped/incomplete tests without justification (add a" >&2
  echo "'trivial-check-allow: <reason>' comment above the line if this is a deliberate conditional skip):" >&2
  echo "$skipped" | sed 's/^/  /' >&2
fi

exit 1
