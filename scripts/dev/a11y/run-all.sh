#!/bin/sh
# R-46 a11y: every accessibility check in one go (dev only). Needs the dev server from serve.sh, a build
# (npm run build) and the fixtures (php scripts/dev/a11y/fixtures.php).
#   sh scripts/dev/a11y/run-all.sh          # about 45 minutes: the sweep alone is about 750 page states
#   sh scripts/dev/a11y/run-all.sh quick    # skips the sweep
# Logs and JSON go to storage/a11y/ (git-ignored). Exit code 1 when anything fails.
cd "$(dirname "$0")/../../.." || exit 1
mkdir -p storage/a11y
fail=0
run() { name="$1"; shift; echo "== $name"; "$@" > "storage/a11y/$name.log" 2>&1 && echo "   passed" || { echo "   FAILED (see storage/a11y/$name.log)"; fail=1; }; tail -n 1 "storage/a11y/$name.log" | sed 's/^/   /'; }
run js-tests node --test "tests/js/*.test.mjs"
run keyboard-390 node scripts/dev/a11y/keyboard.mjs --vp 390
run keyboard-1440 node scripts/dev/a11y/keyboard.mjs --vp 1440 --only pages
run mobile node scripts/dev/a11y/mobile.mjs
run motion node scripts/dev/a11y/motion.mjs
run sunlight node scripts/dev/a11y/sunlight.mjs
if [ "$1" != "quick" ]; then
  run sweep node scripts/dev/a11y/sweep.mjs --ci --json sweep.json
fi
exit $fail
