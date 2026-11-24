#!/usr/bin/env bash
# Is the plugin still declared and checked against the current platform? Compares
#   - readme.txt's "Tested up to" with the newest WordPress,
#   - the plugin header's "WC tested up to" with the newest WooCommerce (when the plugin requires WooCommerce),
#   - the WordPress Coding Standards pinned in .github/wpcs.sh with their newest release,
# and prints a Markdown report. The Compatibility workflow runs it monthly and opens or updates the issue
# "Compatibility check" when something is behind; run it yourself any time:
#
#   .github/compat-check.sh
#
# Exit code 0 with "behind=yes|no" in $GITHUB_OUTPUT (when set); 1 when a newest version cannot be read, so a failed
# lookup is never taken for "up to date". Versions compare as major.minor, as WordPress does.
set -euo pipefail

cd "$(dirname "$0")/.."
header() { sed -n "s/^[[:space:]*#@]*$1:[[:space:]]*//p" "$2" | head -1 | tr -d '\r' | sed 's/[[:space:]]*$//'; }
mm() { cut -d. -f1-2 <<<"$1"; }
older() { [ "$1" != "$2" ] && [ "$(printf '%s\n%s\n' "$1" "$2" | sort -V | head -1)" = "$1" ]; }   # older A B: A < B
fetch() { curl -fsS --retry 3 --max-time 30 "$@"; }

main="$(grep -l '^[[:space:]*]*Plugin Name:' ./*.php | head -1)"
main="${main#./}"
behind=no
rows=()
row() { rows+=("| $1 | $2 | $3 | $4 |"); }

# WordPress.
wp_new="$(fetch 'https://api.wordpress.org/core/version-check/1.7/' | jq -r '.offers[0].current // empty')" || true
[ -n "$wp_new" ] || { echo "cannot read the newest WordPress version" >&2; exit 1; }
wp_tested="$( [ -f readme.txt ] && header 'Tested up to' readme.txt || true)"
if [ -z "$wp_tested" ]; then row WordPress "(no \`Tested up to\` in readme.txt)" "$wp_new" "⚠ add it"; behind=yes
elif older "$(mm "$wp_tested")" "$(mm "$wp_new")"; then row WordPress "$wp_tested" "$wp_new" "⚠ behind"; behind=yes
else row WordPress "$wp_tested" "$wp_new" "✅"
fi

# WooCommerce, when the plugin needs it.
if grep -qiE '^[[:space:]*]*(Requires Plugins:.*woocommerce|WC requires at least:)' "$main"; then
  wc_new="$(fetch 'https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request%5Bslug%5D=woocommerce&request%5Bfields%5D%5Bsections%5D=0' | jq -r '.version // empty')" || true
  [ -n "$wc_new" ] || { echo "cannot read the newest WooCommerce version" >&2; exit 1; }
  wc_tested="$(header 'WC tested up to' "$main")"
  if [ -z "$wc_tested" ]; then row WooCommerce "(no \`WC tested up to\` in $main)" "$wc_new" "⚠ add it"; behind=yes
  elif older "$(mm "$wc_tested")" "$(mm "$wc_new")"; then row WooCommerce "$wc_tested" "$wc_new" "⚠ behind"; behind=yes
  else row WooCommerce "$wc_tested" "$wc_new" "✅"
  fi
fi

# WordPress Coding Standards.
auth=()
[ -n "${GH_TOKEN:-}" ] && auth=(-H "Authorization: Bearer $GH_TOKEN")
wpcs_new="$(fetch "${auth[@]}" https://api.github.com/repos/WordPress/WordPress-Coding-Standards/releases/latest | jq -r '.tag_name // empty')" || true
[ -n "$wpcs_new" ] || { echo "cannot read the newest WordPress Coding Standards release" >&2; exit 1; }
wpcs_pinned="$(sed -nE 's#.*wpcs-([0-9][0-9.]*).*#\1#p' .github/wpcs.sh | head -1)"
if older "$wpcs_pinned" "$wpcs_new"; then row "WordPress Coding Standards" "$wpcs_pinned" "$wpcs_new" "⚠ behind"; behind=yes
else row "WordPress Coding Standards" "$wpcs_pinned" "$wpcs_new" "✅"
fi

cat <<EOF
| | Declared / pinned | Newest | |
| --- | --- | --- | --- |
$(printf '%s\n' "${rows[@]}")

EOF
if [ "$behind" = yes ]; then
  cat <<'EOF'
**What to do** (CLAUDE.md, "Kept current"): read the release notes and developer notes of each newer version for
anything this plugin uses (deprecated functions, changed hooks, HPOS, blocks, PHP), fix what needs fixing, test, and
only then raise `Tested up to` (readme.txt) and `WC tested up to` (plugin header) in the same pull request. A newer
WordPress Coding Standards: update the pinned commits in `.github/wpcs.sh` and fix the new findings.
EOF
fi
[ -z "${GITHUB_OUTPUT:-}" ] || echo "behind=$behind" >> "$GITHUB_OUTPUT"
