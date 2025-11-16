#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP_BIN="${PHP_BIN:-php}"
PYTHON_BIN="${PYTHON_BIN:-python3}"
EXIT_CODE=0

cd "$ROOT_DIR"

echo "==> Running PHP syntax checks"
php_files=()
while IFS= read -r -d '' file; do
    php_files+=("$file")
done < <(find . -name '*.php' -print0 | sort -z)

if ((${#php_files[@]} == 0)); then
    echo "No PHP files found"
else
    for file in "${php_files[@]}"; do
        if ! "$PHP_BIN" -l "$file" > /tmp/check_errors_php.log 2>&1; then
            echo "PHP syntax error in $file"
            cat /tmp/check_errors_php.log
            EXIT_CODE=1
        fi
    done
    echo "PHP syntax checks completed"
fi

rm -f /tmp/check_errors_php.log

echo "==> Running Python bytecode compilation"
python_targets=(ws_emit.py ws_server.py)
for target in "${python_targets[@]}"; do
    if [[ -f "$target" ]]; then
        if ! "$PYTHON_BIN" -m py_compile "$target"; then
            echo "Python compilation failed for $target"
            EXIT_CODE=1
        fi
        target_dir=$(dirname "$target")
        if [[ "$target_dir" == "." ]]; then
            target_dir="$ROOT_DIR"
        fi
        if [[ -d "$target_dir/__pycache__" ]]; then
            rm -rf "$target_dir/__pycache__"
        fi
    fi
done

echo "Python compilation completed"

exit "$EXIT_CODE"
