=== Devenia MCP Updater ===
Contributors: basicus
Tags: mcp, updates, automation, plugins, self-hosted
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.15
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Keep Devenia-managed WordPress plugins current through a Devenia-controlled update channel.

== Description ==

Devenia MCP Updater connects WordPress' native plugin update system to packages published on Devenia-controlled infrastructure.

It supplies packages only for plugins explicitly listed in the signed Devenia channel, which can include a designated upstream plugin. Update information must have a valid Devenia signature, package URLs must use the expected Devenia downloads path, and downloaded packages must match their published SHA-256 hash.

The updater does not require an external Git-hosting account. New Git-backed package identities are anchored to the local commit, tree, and repository path instead of a remote service.

= What It Does =

* Shows eligible Devenia plugin updates in WordPress
* Enables unattended updates for every installed plugin
* Verifies signed update information and exact package hashes
* Allows MCP URL installation only for an exact package in the signed update information
* Accepts locally anchored package identities without a Git remote
* Leaves installed plugins untouched when update information is unavailable or invalid
* Stable plugin download: https://downloads.devenia.com/devenia-mcp-updater.zip

= Requirements =

* WordPress 6.8 or newer
* PHP 7.4 or newer with Sodium support
* WordPress filesystem updates and scheduled update checks working normally
* Network access to `downloads.devenia.com`

= Safety Model =

* Only explicitly listed plugins receive packages from the Devenia channel; plugins outside that list keep their own registered update providers
* Package URLs must use the expected Devenia downloads path
* Every downloaded package must match its published SHA-256 hash
* MCP URL installation must name the exact package in the valid signed update information
* Package file, version, identity, and contents must agree
* A Git remote is not trusted or required for new package identities
* Invalid or unavailable update information does not modify installed plugins

== Installation ==

1. In WordPress Admin, open Plugins > Add New > Upload Plugin
2. Upload the Devenia MCP Updater ZIP
3. Activate Devenia MCP Updater
4. Run a normal WordPress update check

The plugin has no settings screen. Keep a restorable backup, then check the installed version and important site functions after an update; automatic-update eligibility does not prove completion.

== Frequently Asked Questions ==

= Does this enable automatic updates for every plugin on a site? =

Yes, including plugins installed later. The plugin enables the native automatic-update filter for every plugin instead of relying on the saved per-plugin opt-in list. Plugins in the signed Devenia channel receive its packages; other plugins use their own registered update providers. Scheduling, filesystem access, site-wide restrictions, and other update hooks still affect whether an update completes. The plugin does not change theme or WordPress core update policy.

= Does it depend on GitHub or another Git host? =

No. External Git hosting is not required for update discovery, package delivery, or continued operation.

= What happens when update information is unavailable? =

The updater records the problem and leaves installed plugins untouched.

== Changelog ==

= 0.1.15 =

Aligns the tested WordPress version with the current update runtime.

= 0.1.14 =

Adds signed updates for the upstream MCP Adapter release channel.

= 0.1.13 =
* Consolidate signed MCP package installation and managed updates in one plugin.
* Remove the superseded standalone downloads upload gate.

= 0.1.12 =
* Enable unattended updates for every installed plugin, including future plugin installs.

= 0.1.11 =
* Add locally authoritative package identities that do not require or trust a Git remote.
* Keep older signed package identities readable while new identities use the local model.

= 0.1.10 =
* Improve confirmation that an updated plugin finished in its intended activation state.
* Safely reconcile interrupted confirmation on a later request.

= 0.1.9 =
* Add authenticated package-source identities for controlled Git, WordPress.org, and complete local snapshots.
* Reject unknown or malformed package-source information.

= 0.1.8 =
* Verify signed update information before accepting an update.
* Require exact package identity and content-addressed package URLs.
