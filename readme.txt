=== Mr. President ===
Contributors: jackofall1232
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A fictional presidency simulator with monthly decisions, cabinet advice, saved games and optional AI daily briefs.

== Installation ==

1. Download the ZIP from the repository's plugin branch:
   https://github.com/jackofall1232/mr_president/archive/refs/heads/plugin.zip
2. In WordPress, open Plugins > Add New Plugin > Upload Plugin.
3. Upload the ZIP, install it, and activate Mr. President.
4. Open Settings > Mr. President and click Create game page.
5. Visit the game page while logged in to begin your presidency.

Alternatively, add [mr_president_game] to a WordPress page.

== Optional AI ==

The game works without AI. Settings > Mr. President provides two selectors:
AI integration (WordPress Connectors or Offline) and model (Luna, Terra, Sol or Astra).
WordPress Connectors and Luna are the defaults.

AI requires WordPress 7.0+, the OpenAI provider plugin, and credentials configured in
Settings > Connectors. Model availability depends on the connected account and provider.
Daily-brief generation sends public game briefing facts to OpenAI and may incur API
charges. Credentials are managed by WordPress. Cached results reduce repeat requests.
Missing configuration or failed requests fall back to built-in briefings.
Select Offline to disable AI requests. AI does not determine simulation outcomes.

OpenAI service information: https://openai.com/policies/terms-of-use/
OpenAI privacy policy: https://openai.com/policies/privacy-policy/

== Saves and removal ==

Players must log in. Saves belong to their WordPress account and are stored in the
site database. Deactivating preserves saves; deleting the plugin runs its uninstall
routine and removes saved games.

== Development ==

This branch contains the installable plugin only. Source documentation, tests and
development tooling are maintained on the main branch:
https://github.com/jackofall1232/mr_president/tree/main

Version 0.2.0 includes president creation, separate House/Senate elections, midterms,
approval-based reelection and one-term/eight-year legacy endings. Approval must be
strictly above 50% at the first presidential election to win a second term.
Legislative negotiations, cabinet appointments, judiciary gameplay and sourced
historical/current-event feeds are not included yet.

== Changelog ==

= 0.2.0 =
Add president profiles, congressional elections, reelection and campaign endings.
Add responsive campaign milestones, chamber cards, election history and legacy reports.
Migrate existing saves to campaign schema 2 without resetting their random state.
Preserve the fullscreen black-screen fix. AI remains optional.

= 0.1.1 =
Fix a black screen caused by site-wide body filter animations collapsing the
standalone game's fixed-position container. The fix applies only to the game page.
