#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FIXTURE="$ROOT_DIR/tools/bookstack-screenshots/fixture.html"
OUTPUT_DIR="$ROOT_DIR/docs/bookstack/kairos/screenshots"
CHROME_BIN="${CHROME_BIN:-$(command -v google-chrome || command -v chromium || command -v chromium-browser || true)}"

if [[ -z "$CHROME_BIN" ]]; then
  echo "Google Chrome or Chromium is required to generate screenshots." >&2
  exit 1
fi

mkdir -p "$OUTPUT_DIR"

views=(
  login
  activation
  dashboard
  settings
  student-modules
  student-assignment
  student-notes
  student-quiz
  student-feedback
  ta-dashboard
  grading-workspace
  manager-dashboard
  manager-course-settings
  manager-modules
  assignment-editor
  quiz-editor
  analytics
  admin-dashboard
  local-account-invite
  pending-accounts
  course-staff
)

for view in "${views[@]}"; do
  "$CHROME_BIN" \
    --headless \
    --disable-gpu \
    --hide-scrollbars \
    --no-sandbox \
    --allow-file-access-from-files \
    --window-size=1440,1000 \
    --screenshot="$OUTPUT_DIR/$view.png" \
    "file://$FIXTURE?view=$view" >/dev/null 2>&1
done

echo "Generated ${#views[@]} screenshots in $OUTPUT_DIR"
