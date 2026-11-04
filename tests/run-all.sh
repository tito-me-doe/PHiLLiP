#!/usr/bin/env bash
# Run every test: `tests/run-all.sh` (deterministic suite) or `tests/run-all.sh --live` (also the live-model tests).
#
# Most tests run on the host. A few need tools the host may lack (pdo_mysql/pdo_sqlite, the remote browser service, a mapped
# `www-data` Unix account) and run inside the application container instead. Every test gets its own disposable database;
# none touches production (see docs/testing.md). Output of passing tests is hidden; failing ones are printed.
cd "$(dirname "$0")/.." || exit 2
IN_CONTAINER=" browser infrastructure mappings cli_memory attachment_media "
LIVE=" streaming attachment_vision cli_dogfood agent_team_live "
passed=0; failed=()
run() { # name, command...
  local name=$1; shift
  if out=$("$@" 2>&1); then passed=$((passed + 1)); echo "ok   $name"
  else failed+=("$name"); echo "FAIL $name"; printf '%s\n' "$out" | grep -av '^\[Debug\]\|^\[Info\]\|^\[Success\]\|Accepted\|Closing\|Closed' | tail -15; fi
}
for file in tests/*.php; do
  name=$(basename "$file" .php)
  case "$name" in helpers|run|live_ui|project_recursive_live) continue;; esac
  if [[ "$LIVE" == *" $name "* ]]; then [[ " $* " == *" --live "* ]] || continue; fi
  if [[ "$IN_CONTAINER" == *" $name "* ]]; then run "$name" docker compose exec -T app php "$file"; else run "$name" php "$file"; fi
done
if [[ " $* " == *" --live "* ]]; then
  run live_ui php tests/live_ui.php
  run project_recursive_live php tests/project_recursive_live.php --profile default
fi
echo; echo "$passed passed, ${#failed[@]} failed${failed[*]:+: ${failed[*]}}"
[[ ${#failed[@]} -eq 0 ]]
