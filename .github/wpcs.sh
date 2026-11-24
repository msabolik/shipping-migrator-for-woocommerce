#!/usr/bin/env bash
# WordPress Coding Standards for this repository (rules: .github/phpcs.xml.dist). CI runs it in the Lint workflow;
# run the same thing before every push:
#
#   .github/wpcs.sh            check: fails on a finding that is not in .github/phpcs-baseline.json, and on a
#                              baseline entry that no longer occurs (then shrink the baseline: see below)
#   .github/wpcs.sh fix        let phpcbf fix what it can (spacing, braces, array syntax), then check
#   .github/wpcs.sh baseline   write the current findings to .github/phpcs-baseline.json: when adopting the
#                              standards in existing code, or to shrink it after fixing old findings. Never to let
#                              a new finding through.
#
# The tools come from GitHub at pinned commits (no Composer, nothing added to the repository), cached in
# ~/.cache/wpcs-<version> (WPCS_TOOLS overrides). Needs php and git.
set -euo pipefail

cd "$(dirname "$0")/.."
mode="${1:-check}"
case "$mode" in check | fix | baseline) ;; *) sed -n '2,13p' "$0"; exit 2 ;; esac
tools="${WPCS_TOOLS:-${XDG_CACHE_HOME:-$HOME/.cache}/wpcs-3.4.1}"
ruleset=.github/phpcs.xml.dist
baseline=.github/phpcs-baseline.json

# fetch <dir> <GitHub repository> <commit>
fetch() {
  local dir="$tools/$1"
  [ "$(git -C "$dir" rev-parse HEAD 2>/dev/null)" = "$3" ] && return
  rm -rf "$dir"
  mkdir -p "$dir"
  git -C "$dir" init -q
  git -C "$dir" fetch -q --depth 1 "https://github.com/$2" "$3"
  git -C "$dir" -c advice.detachedHead=false checkout -q FETCH_HEAD
}
fetch phpcs PHPCSStandards/PHP_CodeSniffer 4c378e1a528ea066890fc2397cbdd2f94eb2fc91        # 3.13.6
fetch wpcs WordPress/WordPress-Coding-Standards ec2ff942335f33683a5957a85d138753876a05cf   # 3.4.1
fetch phpcsutils PHPCSStandards/PHPCSUtils 5f35d9408c54d7b529501f3c688b6eae562aea1f        # 1.2.3
fetch phpcsextra PHPCSStandards/PHPCSExtra 39467533fdb742446d68c1d10ac33d625ee0311c        # 1.5.1
php "$tools/phpcs/bin/phpcs" --config-set installed_paths "$tools/wpcs,$tools/phpcsutils,$tools/phpcsextra" >/dev/null

if [ "$mode" = fix ]; then
  php "$tools/phpcs/bin/phpcbf" -q --standard="$ruleset" || true # exits 1 when it fixed something
fi

report="$(mktemp)"
trap 'rm -f "$report"' EXIT
# Exit status 1/2 only means findings; without a report the run itself failed, and its output says why.
phpcs_output="$(php "$tools/phpcs/bin/phpcs" -q --standard="$ruleset" --report=json --report-file="$report" 2>&1)" || true
[ -s "$report" ] || { printf '%s\n' "$phpcs_output" >&2; exit 2; }

# Compare the findings, counted per file and sniff, with the baseline.
php -d error_reporting=-1 -- "$report" "$baseline" "$mode" <<'PHP'
<?php
[, $reportFile, $baselineFile, $mode] = $argv;
$report = json_decode((string) file_get_contents($reportFile), true);
if (!is_array($report) || !isset($report['files'])) {
    fwrite(STDERR, "phpcs did not produce a report (see its output above).\n");
    exit(2);
}
$found = [];    // file => sniff => count
$messages = []; // file => sniff => list of messages
foreach ($report['files'] as $file => $result) {
    foreach ($result['messages'] as $m) {
        $found[$file][$m['source']] = ($found[$file][$m['source']] ?? 0) + 1;
        $messages[$file][$m['source']][] = $m;
    }
}
ksort($found);
foreach ($found as &$sniffs) {
    ksort($sniffs);
}
unset($sniffs);

if ($mode === 'baseline') {
    file_put_contents($baselineFile, json_encode((object) $found, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    printf("Wrote %s: %d findings in %d files.\n", $baselineFile, array_sum(array_map('array_sum', $found)), count($found));
    exit(0);
}

$known = is_file($baselineFile) ? json_decode((string) file_get_contents($baselineFile), true) : [];
$github = getenv('GITHUB_ACTIONS') === 'true';
$new = 0;
$stale = 0;
foreach ($messages as $file => $sniffs) {
    foreach ($sniffs as $sniff => $list) {
        $allowed = $known[$file][$sniff] ?? 0;
        if (count($list) <= $allowed) {
            continue;
        }
        $new += count($list) - $allowed;
        foreach ($list as $m) {
            $note = $allowed > 0 ? sprintf(' (%d in this file, baseline allows %d)', count($list), $allowed) : '';
            if ($github) {
                printf("::error file=%s,line=%d,col=%d,title=%s::%s%s\n", $file, $m['line'], $m['column'], $sniff,
                    strtr($m['message'], ["\n" => ' ', '%' => '%25']), $note);
            } else {
                printf("%s:%d:%d: %s [%s]%s\n", $file, $m['line'], $m['column'], $m['message'], $sniff, $note);
            }
        }
    }
}
foreach ($known as $file => $sniffs) {
    foreach ($sniffs as $sniff => $allowed) {
        $count = $found[$file][$sniff] ?? 0;
        if ($count < $allowed) {
            $stale++;
            printf("%s%s: %s now occurs %d times, the baseline still allows %d\n",
                $github ? '::error title=Baseline out of date::' : '', $file, $sniff, $count, $allowed);
        }
    }
}
$total = array_sum(array_map('array_sum', $found));
printf("WordPress Coding Standards: %d findings, %d of them new; %d baseline entries out of date.\n", $total, $new, $stale);
if ($new > 0) {
    echo "Fix the new findings (.github/wpcs.sh fix handles formatting). A finding that is right on purpose gets a\n",
        "// phpcs:ignore Sniff.Name -- reason comment on that line.\n";
}
if ($stale > 0) {
    echo "Old findings were fixed: run .github/wpcs.sh baseline and commit .github/phpcs-baseline.json.\n";
}
exit($new > 0 || $stale > 0 ? 1 : 0);
PHP
