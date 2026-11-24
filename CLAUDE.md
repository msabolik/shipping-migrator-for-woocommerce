# wc-shipping-migrator: rules for Claude

A **public** WordPress plugin: anyone can read this repository, its whole history, its issues and its pull requests.
It is free software under the GNU GPL (`LICENSE`), published on GitHub (releases) and possibly on WordPress.org. It
is not deployed to any server. **You write code and open pull requests. You never merge, never publish a release and
never change the repository's visibility or license.**

## Every session

1. Read this file, `docs/HANDOFF.md`, and the issue or request you were given.
2. Start from the current `main` (`git fetch origin`) and branch as `claude/<short-topic>`. Never commit to `main`.
   If `main` moves while you work, merge `origin/main` into your branch before you push.
3. Before every push: `.github/license-check.sh`, the PHP lint
   (`find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l`) and the WordPress Coding Standards
   (`.github/wpcs.sh`; `.github/wpcs.sh fix` fixes formatting). Without PHP in your session, CI runs them.
4. Before you stop, even mid-task: update `docs/HANDOFF.md`, push, and open or update the pull request.
5. Record any decision the owner makes in `docs/DECISIONS.md` (one dated line).

## Everything here is public

- Write every file, commit message, issue and pull request for strangers. Never put in them: client or customer
  names, store data, export files, server names or addresses, local paths, other repositories or anything else of
  the owner's private setup beyond what this file says, credentials, keys or tokens. A secret pushed once stays in
  the history even after a later commit removes it: GitHub's push protection blocks the common ones; nothing should
  get that far.
- Removing something from a later commit does not unpublish it. If something private or unpublishable is already on
  GitHub, say so at once (see "Licensing", the stop rule).

## Licensing

The rules that decide whether this code may be published at all. `.github/license-check.sh` checks what a script
can; the rest is your judgement, and it comes before every other rule here.

1. **The license is GPL-2.0-or-later**, as WordPress itself: the full text in `LICENSE`, `License: GPL-2.0-or-later`
   and `License URI: https://www.gnu.org/licenses/gpl-2.0.html` in the plugin header and in `readme.txt`. A plugin
   that calls WordPress and WooCommerce functions is a derivative work of GPL code, so the GPL is not optional.
   Never change the license: that is the owner's decision, and contributions he does not own can block it.
2. **Only code we may publish:** code written here (by the owner or by you), or code under a license compatible
   with GPL-2.0-or-later: GPL-2.0-or-later, GPL-2.0-only, GPL-3.0, LGPL-2.1, LGPL-3.0, MIT, BSD-2-Clause,
   BSD-3-Clause, ISC, Zlib, CC0-1.0, Unlicense, and for fonts OFL-1.1, for images CC-BY-4.0. Apache-2.0, MPL-2.0 and
   anything else need the owner's decision first.
3. **Never copy** from a commercial or premium plugin or theme (marketplace licences such as CodeCanyon/Envato,
   WooCommerce.com extensions, "pro" versions), from a client's or employer's project, from a site's live code, from
   code without a stated license, or from a tutorial or forum answer as is (write it yourself instead). Reading
   such code to understand an API is fine; its lines and structure do not come here.
4. **Bundled third-party code and assets** (a library, a CSS or JS file, a font, an icon set, an image) are listed in
   `CREDITS.md`: path, name, version, license (SPDX), source URL. Their license file and copyright notices stay with
   them, untouched. The license check fails on a library folder or a foreign copyright notice that `CREDITS.md`
   does not list.
5. **Contributions** from other people are accepted only under this repository's license (inbound = outbound): say
   so when you review one, and check it against rules 2 to 4 like your own code.
6. **The stop rule.** If you find code, an asset or data that may not be published (rules 2 to 4, private data,
   secrets), **stop**: change nothing else, push nothing, and tell the owner at once, with the files and why. While
   it is in the repository, or in its history, the repository may not be public. The owner makes it private
   (`gh repo edit msabolik/wc-shipping-migrator --visibility private --accept-visibility-change-consequences`), it is
   removed or replaced (and, where needed, removed from the history, which only the owner does), and only then is it
   made public again. A new public repository starts private and is made public only after this check passes.

## WordPress and WooCommerce standards

The owner's standing rule for every repository: code follows the platform's own best practice, is secure by default,
minimal and modern.

- **Standards:** WordPress Coding Standards (PHP, JavaScript, CSS, HTML, accessibility), the Plugin Handbook and the
  WordPress.org plugin guidelines: core APIs first (Settings, REST API with a `permission_callback`, HTTP API,
  Filesystem, transients, WP-Cron or Action Scheduler), hooks rather than overrides, one unique prefix (`wcsm`),
  every user-facing string translatable (text domain `wc-shipping-migrator`), assets enqueued with a version and
  only where they are used, nothing loaded from a CDN, no tracking or calls home without the site owner opting in,
  no obfuscated or minified-only code (ship the source).
- **WooCommerce** (when the plugin extends it): only through its CRUD classes, data stores and hooks, never its
  tables directly unless no API exists (then say why in a comment); HPOS-compatible (no `wp_posts` queries for
  orders) and declared with `FeaturesUtil::declare_compatibility()` for every feature the plugin supports;
  `Requires Plugins: woocommerce`, `WC requires at least` and `WC tested up to` in the plugin header.
- **Security:** treat every input as untrusted (validate and sanitise it, files and imports included), escape on
  output (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`), `$wpdb->prepare()` for every query with input, a nonce
  and a capability check (`current_user_can`) on every action, AJAX and REST handler, least privilege. Check each
  change that touches input, sessions, files or outside calls against the OWASP Top 10. A vulnerability is never
  described in a public issue, commit or pull request before its fix is released (reports come privately, see
  `SECURITY.md`): if you find one, tell the owner in the chat and change nothing yet. He decides how the fix is made
  (usually a GitHub security advisory with its temporary private fork) and when it is published.
- **Minimal and modern:** the smallest change that does the job well; current language and platform features within
  the versions the header supports (`Requires at least`, `Requires PHP`), nothing deprecated; clear names, small
  functions, comments that say why rather than what.
- **Checked:** `.github/wpcs.sh` (CI's Lint) fails on any finding of `.github/phpcs.xml.dist` that is not in
  `.github/phpcs-baseline.json`. The baseline only shrinks: never add to it to let a new finding through. Code that
  is right on purpose gets `// phpcs:ignore Sniff.Name -- reason` on that line.
- **Kept current:** the Compatibility workflow compares, every month, `readme.txt`'s `Tested up to` and the header's
  `WC tested up to` with the newest WordPress and WooCommerce, and `.github/wpcs.sh`'s pinned WordPress Coding
  Standards with their newest release, and runs WordPress's Plugin Check. When it opens or updates the issue
  "Compatibility check": read the release notes and developer notes of the new versions for anything this plugin
  uses (deprecated functions, changed hooks, HPOS, blocks, PHP), fix what needs fixing, then raise the tested-up-to
  values in the same pull request, whose description lists what you read and how to test it on a site with those
  versions (the owner tests there before he merges). Never raise them without that reading. A new WPCS release:
  update the pinned commits in `.github/wpcs.sh` and fix the findings it adds.

## Versions and releases

- Semantic versioning. Every pull request that changes behaviour bumps the version in the plugin header, the
  version constant, `readme.txt` (`Stable tag`) and adds a `CHANGELOG.md` entry (Keep a Changelog).
- Database changes run on version change through an upgrade routine, never on every request. Uninstalling removes
  only the plugin's own data, never the data it manages for WooCommerce.
- Releases (tag `vX.Y.Z` and a GitHub release with the plugin zip) are the owner's. `.gitattributes` keeps the
  repository-only files (`.github/`, `docs/`, `CLAUDE.md`, `CREDITS.md` stays in) out of the release archive.

## Steps only the owner can do

Merging, releases, the repository's visibility and settings, secrets, and anything on wordpress.org. Never only
describe such a step: give it ready to paste in Git Bash on his PC (`cd` into his folder for that repository first
when it needs one), one step at a time, with what output to send back. Never ask for a secret in the chat: he types
it at the tool's prompt. A command you do not know exactly: say what has to happen and that the owner's own session
gives the command. Never guess an option.

## Pull request description

Public, so write it for any reader:

- What changed and why, in two or three sentences.
- How to test it on a WordPress site (with WooCommerce when the plugin extends it), with exact screens and steps.
- The version bump and the `CHANGELOG.md` entry, and any licensing note (new third-party code: its `CREDITS.md`
  line).

The owner's merge lines go in your reply in the chat, not in the pull request: on his PC `merge-pr.sh
msabolik/wc-shipping-migrator <n>` from his devops folder, on his phone `merge wc-shipping-migrator <n>`.
