# Devenia MCP Updater

Private update channel and automatic sync for Devenia MCP and Abilities plugins.

[![GitHub release](https://img.shields.io/github/v/release/bjornfix/devenia-mcp-updater)](https://github.com/bjornfix/devenia-mcp-updater/releases)
[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0)
[![WordPress](https://img.shields.io/badge/WordPress-6.8%2B-blue.svg)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)](https://php.net)

**Tested up to:** 7.0
**Stable tag:** 0.1.9
**License:** GPLv2 or later
**License URI:** https://www.gnu.org/licenses/gpl-2.0.html

## What It Does

Private update channel and automatic sync for Devenia MCP and Abilities plugins.

This plugin is part of the Devenia MCP abilities ecosystem. It gives an MCP-capable agent a focused, authenticated way to work with Devenia-managed WordPress plugin updates.

**Example:** "Handle this WordPress maintenance task directly." - The agent can inspect the site, call the relevant ability, and return the result without making the human click through wp-admin for every step.

## The Real Workflow

In practice, the human should not have to memorize every ability name.

The normal pattern is:

1. install the base MCP stack
2. install only the add-ons the site actually needs
3. let the agent discover the available abilities
4. give the agent a clear task with boundaries
5. verify the result in WordPress

The human's job is mostly to describe the goal.
The agent's job is to figure out the mechanics.

## Why This Feels Different

Most WordPress automation still leaves the repetitive part to the human.

This plugin is different because the agent can act inside the site through a narrow, authenticated ability surface:

- inspect current site state before changing anything
- run the specific action needed for the task
- return structured results that are easy to verify
- keep the workflow inside WordPress instead of a separate checklist

That changes the experience from:

- `Here is what you should do in wp-admin`

to:

- `Tell the agent what needs doing, and let it carry out the work`

## Before vs After

### Before

- ask the AI what to do
- copy the answer into WordPress by hand
- click through wp-admin for the repetitive bits
- postpone maintenance because the task is tedious

### After

- tell the agent what needs doing
- let it inspect the relevant WordPress state
- let it run the targeted ability
- verify the result and move on

## Who It Is For

This is a good fit for:

- teams maintaining Devenia MCP plugins across WordPress sites
- operators who want predictable plugin update checks
- sites that should receive managed plugin releases without manual ZIP uploads
- maintenance workflows that need a clear release channel

It is especially useful when the manual version is repetitive enough that important maintenance gets delayed.

## Documentation

Start with the main plugin page and base stack documentation:

- [MCP Expose Abilities](https://devenia.com/plugins/mcp-expose-abilities/)
- [Plugin Page](https://devenia.com/plugins/devenia-mcp-updater/)
- [Getting Started](https://github.com/bjornfix/mcp-expose-abilities/wiki/Getting-Started)
- [Install Order and Dependencies](https://github.com/bjornfix/mcp-expose-abilities/wiki/Install-Order-and-Dependencies)

If you are using an AI agent, the simplest instruction is often just:

- `Read https://github.com/bjornfix/mcp-expose-abilities and figure out the stack before making changes.`

## Start Here

If you are new to the stack, use this order:

1. Install **Devenia MCP Updater** on the WordPress site.
2. Confirm the update manifest URL is configured.
3. Keep managed Devenia MCP plugins installed from the release ZIPs.
4. Let the updater handle future release checks.

If you skip base-stack verification and start with add-ons immediately, troubleshooting gets harder than it needs to be.

## Safety Model

- The updater does not install arbitrary plugins.
- The updater only manages plugins explicitly listed in the manifest.
- The updater only accepts packages hosted under the Devenia downloads path.
- Every package is verified with SHA256 before WordPress installs it.
- Stale duplicate folders are removed only after the canonical manifest plugin is installed, during activation, explicit refresh, or plugin upgrade flows.
- Manifest entries must reference a passed Plugin Check report for the same SHA256.
- If the manifest is unavailable or invalid, the updater records status and leaves installed plugins untouched.

## Changelog

### 0.1.9

- Accept authenticated release identity schema 2 for Git, WordPress.org SVN, and local snapshot package sources.
- Keep unknown or malformed source identities fail-closed before WordPress exposes an update.

### 0.1.8

- Verifies an Ed25519-signed manifest envelope before consuming update entries.
- Requires release identity plus strict zero-finding Plugin Check evidence.
- Supports immutable content-addressed package URLs.

### 0.1.7

- Supplies exact manifest-gated update approval through MCP Expose's neutral plugin-update policy seam, keeping private manifest knowledge out of the public plugin.

### 0.1.6

- Removes periodic duplicate-folder reconciliation from normal admin page loads. Reconciliation now runs only during activation, explicit refresh, or plugin upgrade flows.

### 0.1.5

- Marks the canonical plugin active for the next request during duplicate cleanup instead of loading a second copy in the current request.

### 0.1.4

- Deactivates stale duplicate plugin copies before activating the canonical manifest plugin to avoid duplicate PHP declarations.

### 0.1.3

- Detects stale duplicate folders for manifest-managed plugins after activation, plugin upgrades, and periodic admin checks.
- Moves active state from a stale duplicate folder to the canonical manifest plugin before deleting the duplicate.

### 0.1.2

- Accepts only the canonical `https://downloads.devenia.com/<plugin>.zip` package channel at runtime.

### 0.1.1

- Moves the private manifest and package channel to `https://downloads.devenia.com/`.

### 0.1.0

- Initial private MCP update channel.
- Adds private manifest support for known MCP/Abilities plugins.
- Enables auto-update for manifest-managed plugins.
- Verifies staged package SHA256 before install.
- Requires a passed Plugin Check gate for manifest entries.
- Records compact update status.

## Contributing

PRs welcome. Keep changes focused on the plugin's WordPress ability surface and preserve authenticated, explicit workflows.

## License

GPL-2.0+

## Author

[Devenia](https://devenia.com) - We've been doing SEO and web development since 1993.

## Links

- [Plugin Page](https://devenia.com/plugins/devenia-mcp-updater/)
- [MCP Expose Abilities](https://devenia.com/plugins/mcp-expose-abilities/)
- [GitHub Releases](https://github.com/bjornfix/devenia-mcp-updater/releases)

## Star and Share

If this plugin saves you time or makes WordPress maintenance easier to verify, please:

- star the repo
- share it with people running WordPress sites
- point them to the main plugin page so they can see what the ecosystem can actually do

Why do it?

Because agent-friendly open WordPress tooling helps more of the boring but important work get done.
