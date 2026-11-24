#!/usr/bin/env bash
# May this repository be published as it is? The licensing rules of CLAUDE.md that a script can check. CI runs it in
# the Lint workflow on every pull request; run it before every push too:
#
#   .github/license-check.sh
#
# It fails when:
#   - LICENSE (or COPYING) is missing or is not the GNU GPL;
#   - the plugin header has no License or License URI, or a license other than GPL-2.0-or-later / GPL-3.0 (WordPress
#     accepts only GPL-compatible plugins), or readme.txt names a different one;
#   - a shipped file carries a marker of code that may not be published: "all rights reserved", "proprietary",
#     "confidential", "not for redistribution", a marketplace licence (Envato, CodeCanyon, ThemeForest, "Regular
#     License", "Extended License") or "nulled";
#   - a shipped file has a copyright notice of someone other than the plugin's Author, and CREDITS.md does not list it;
#   - a library in a library folder (vendor/, lib/, libs/, third-party/, third_party/, external/) is not listed in
#     CREDITS.md, or CREDITS.md lists a license outside the allowed ones.
# Shipped files are the tracked files outside .github/, docs/, tests/ and CLAUDE.md. A line that is right on purpose
# carries "license-check: ok" and the reason.
set -euo pipefail

cd "$(dirname "$0")/.."
problems=0
problem() { echo "::error${2:+ file=$2}::$1"; problems=$((problems + 1)); }
# Folded to lower-case ASCII (Sabolić = sabolic), so a name matches however a file spells it.
fold() { LC_ALL=C.UTF-8 iconv -f utf-8 -t ascii//TRANSLIT 2>/dev/null <<<"$1" | tr '[:upper:]' '[:lower:]'; }
trim() { local s="${1#"${1%%[![:space:]]*}"}"; printf '%s' "${s:0:120}"; }
text() { [ -f "$1" ] && grep -Iq . "$1"; } # a text file (not binary, not empty)
header() { sed -n "s/^[[:space:]*#@]*$1:[[:space:]]*//p" "$2" | head -1 | tr -d '\r' | sed 's/[[:space:]]*$//'; }

# Licenses allowed for bundled code and assets (SPDX). Anything else is the owner's decision first.
allowed='GPL-2.0-or-later GPL-2.0-only GPL-2.0 GPL-3.0-or-later GPL-3.0-only GPL-3.0 LGPL-2.1-or-later LGPL-2.1-only
LGPL-3.0-or-later LGPL-3.0-only MIT BSD-2-Clause BSD-3-Clause ISC Zlib CC0-1.0 Unlicense OFL-1.1 CC-BY-4.0'

# 1. LICENSE is the GNU GPL.
license_file=''
for f in LICENSE LICENSE.txt LICENSE.md COPYING; do [ -f "$f" ] && { license_file="$f"; break; }; done
if [ -z "$license_file" ]; then problem "no LICENSE file: add the full text of the GNU GPL version 2"
elif ! grep -q 'GNU GENERAL PUBLIC LICENSE' "$license_file"; then problem "$license_file is not the GNU GPL" "$license_file"
fi

# 2. The plugin header (and readme.txt) declare a GPL license.
main="$(grep -l '^[[:space:]*]*Plugin Name:' ./*.php 2>/dev/null | head -1 || true)"
main="${main#./}"
if [ -z "$main" ]; then
  problem "no main plugin file (a PHP file in the root with a Plugin Name: header)"
else
  lic="$(header License "$main")" uri="$(header 'License URI' "$main")"
  gpl='^(GPL-?v?2(\.0)?(\+|-or-later| or later)|GPL-?v?3(\.0)?(\+|-or-later|-only| or later)?)$'
  if [ -z "$lic" ]; then problem "the plugin header has no License: line (GPL-2.0-or-later)" "$main"
  elif ! grep -qiE "$gpl" <<<"$lic"; then problem "the plugin header's license '$lic' is not GPL-2.0-or-later or GPL-3.0" "$main"
  fi
  [ -n "$uri" ] || problem "the plugin header has no License URI: line" "$main"
  if [ -f readme.txt ]; then
    rlic="$(header License readme.txt)"
    [ -z "$rlic" ] || [ "$(fold "$rlic")" = "$(fold "$lic")" ] \
      || problem "readme.txt's license '$rlic' is not the header's '$lic'" readme.txt
  fi
fi
author="$( [ -n "$main" ] && fold "$(header Author "$main")" || true)"

# What ships: tracked files outside the repository-only folders.
mapfile -t shipped < <(git ls-files | grep -vE '^(\.github/|docs/|tests/|CLAUDE\.md$)' || true)
credits=''
[ -f CREDITS.md ] && credits="$(cat CREDITS.md)"
listed() { # listed PATH: is PATH, or a folder above it, the path of a row of CREDITS.md?
  local p="$1"
  while [ -n "$p" ] && [ "$p" != . ]; do
    grep -qF "| \`$p\` |" <<<"$credits" && return 0
    p="$(dirname "$p")"
  done
  return 1
}

# 3. Markers of code that may not be published.
markers='all rights reserved|proprietary|confidential|not for redistribution|envato|codecanyon|themeforest|regular licen[cs]e|extended licen[cs]e|nulled'
for f in "${shipped[@]}"; do
  case "$f" in LICENSE* | COPYING | CREDITS.md) continue ;; esac
  text "$f" || continue
  while IFS=: read -r n line; do
    grep -q 'license-check: ok' <<<"$line" && continue
    problem "line $n has a marker of code that may not be published: $(trim "$line")" "$f"
  done < <(grep -inE "$markers" "$f" || true)
done

# 4. Copyright notices of anyone but the Author need a CREDITS.md row.
for f in "${shipped[@]}"; do
  case "$f" in LICENSE* | COPYING | CREDITS.md) continue ;; esac
  text "$f" || continue
  listed "$f" && continue
  while IFS=: read -r n line; do
    grep -q 'license-check: ok' <<<"$line" && continue
    [ -n "$author" ] && grep -qF "$author" <<<"$(fold "$line")" && continue
    problem "line $n is someone else's copyright notice and CREDITS.md does not list the file: $(trim "$line")" "$f"
  done < <(grep -inE 'copyright[[:space:]]*(\(c\)|©|[0-9]{4})|©[[:space:]]*[0-9]{4}' "$f" || true)
done

# 5. Everything in a library folder is listed (each library, the folder's next level, or the whole folder), with an
#    allowed license.
mapfile -t libs < <(printf '%s\n' "${shipped[@]}" \
  | sed -nE 's#^((.*/)?(vendor|lib|libs|third-party|third_party|external)/[^/]+).*#\1#p' | sort -u)
for d in "${libs[@]}"; do
  [ -n "$d" ] || continue
  listed "$d" || problem "$d is bundled code that CREDITS.md does not list (path, name, version, license, source)" "$d"
done
while IFS='|' read -r _ path _ _ license _; do
  path="${path//[[:space:]\`]/}" license="${license//[[:space:]\`]/}"
  [ -n "$path" ] || continue
  case " $(tr '\n' ' ' <<<"$allowed") " in
    *" $license "*) ;;
    *) problem "CREDITS.md: $path has the license '$license', which needs the owner's decision first (allowed: $(tr '\n' ' ' <<<"$allowed"))" CREDITS.md ;;
  esac
done < <(grep -E '^\|[[:space:]]*`' CREDITS.md 2>/dev/null || true)

if [ "$problems" -gt 0 ]; then
  echo "license-check: $problems problem(s). This code may not be published until they are fixed (CLAUDE.md, Licensing)."
  exit 1
fi
echo "license-check: ok (${#shipped[@]} shipped files, ${#libs[@]} bundled item(s))"
