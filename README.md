# Devenia MCP Updater

Keep Devenia-managed WordPress plugins current through a Devenia-controlled update channel.

[![Release 0.1.15](https://img.shields.io/badge/release-0.1.15-blue.svg)](https://downloads.devenia.com/devenia-mcp-updater.zip)
[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0)
[![WordPress](https://img.shields.io/badge/WordPress-6.8%2B-blue.svg)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)](https://php.net)

**Tested up to:** 7.1

**Stable tag:** 0.1.15

**License:** GPLv2 or later

**Tags:** mcp, updates, automation, plugins, self-hosted

## What It Does

Devenia MCP Updater connects WordPress' native update system to packages published on Devenia-controlled infrastructure and enables unattended updates for every installed plugin. Only explicitly listed Devenia plugins receive packages from the private channel; other plugins continue to use their own registered WordPress update providers.

- Shows eligible Devenia plugin updates in the normal WordPress update flow.
- Enables unattended updates for every installed plugin, including future installs.
- Verifies the signed update list and the exact package hash.
- Allows MCP URL installation only for an exact package in the signed update list.
- Accepts locally anchored package identities without relying on an external Git host.
- Leaves installed plugins untouched when update information is unavailable or invalid.

## The Real Workflow

1. Install and activate the updater once.
2. WordPress checks the Devenia update channel during its normal update cycle.
3. Listed Devenia plugins receive validated private-channel offers; other plugins keep their own update providers.
4. An MCP URL installation is admitted only when it names the exact listed package.
5. WordPress downloads the package from Devenia infrastructure and verifies its hash.
6. The normal WordPress plugin process installs the approved package.

There is no separate settings screen and no external repository account to configure.

## Why This Feels Different

The site does not need to follow releases on a third-party code-hosting platform or copy ZIP files between services. Update discovery and package delivery stay within WordPress and Devenia-controlled infrastructure while using WordPress' familiar update experience.

## Before vs After

### Before

- Watch several external release channels.
- Download and upload plugin ZIPs manually.
- Check whether the downloaded file is the intended build.

### After

- See managed updates in WordPress.
- Receive packages from one Devenia-controlled channel.
- Let signature and hash checks reject unexpected update data.

## Who It Is For

- WordPress sites using Devenia MCP or Abilities plugins.
- Operators who want managed updates without depending on an external Git host.
- Teams that prefer WordPress-native update handling and self-controlled package delivery.

## Requirements

- WordPress 6.8 or newer.
- PHP 7.4 or newer with Sodium support.
- WordPress filesystem updates and scheduled update checks working normally.
- Network access from WordPress to `downloads.devenia.com`.

## Documentation

- [Devenia MCP Updater plugin page](https://devenia.com/plugins/devenia-mcp-updater/)
- [MCP Expose Abilities plugin page](https://devenia.com/plugins/mcp-expose-abilities/)

## Start Here

Install Devenia MCP Updater before the managed Devenia plugins. Activate it, run a normal WordPress update check, and confirm that installed managed plugins are recognized. Future eligible versions then appear through WordPress' standard plugin update interface.

## Public Interface

The plugin integrates with WordPress' native plugin-update hooks and the structured MCP plugin-upload policy seam. It has no public REST endpoint, no front-end output, and no settings screen. Sites receive private-channel entries only for explicitly managed plugin files, while WordPress automatically installs eligible updates for every plugin.

## Safety and Ownership

- The update list must have a valid Devenia signature.
- Package URLs must use the expected Devenia downloads path.
- Each package must match its published SHA-256 hash.
- MCP URL installation must name the exact package in the valid signed update list.
- The plugin file, version, package identity, and package contents must agree.
- New local Git package identities use commit, tree, and repository path; a Git remote is not trusted or required.
- An invalid or unavailable update list causes no installed-plugin mutation.

## Installation

1. In WordPress Admin, open **Plugins → Add New → Upload Plugin**.
2. Upload the Devenia MCP Updater ZIP.
3. Activate **Devenia MCP Updater**.
4. Run a normal WordPress update check.

## Recent Changes

### 0.1.15

Aligns the tested WordPress version with the current update runtime.

### 0.1.14

Adds signed updates for the upstream MCP Adapter release channel.

### 0.1.13

- Consolidates signed MCP package installation and managed updates in one plugin.
- Removes the superseded standalone downloads upload gate.

### 0.1.12

- Enables unattended updates for every installed plugin, including future installs.

### 0.1.11

- Adds locally authoritative package identities that do not require or trust a Git remote.
- Keeps older signed package identities readable while new identities use the local model.

### 0.1.10

- Improves confirmation that an updated plugin finished in its intended activation state.
- Safely reconciles interrupted confirmation on a later request.

### 0.1.9

- Adds authenticated package-source identities for controlled Git, WordPress.org, and complete local snapshots.
- Rejects unknown or malformed package-source information.

## Contributing

Keep changes focused on predictable WordPress update behavior, self-controlled distribution, and fail-closed validation. Public documentation must describe user-visible behavior without exposing private operating procedures.

## License

GPL-2.0-or-later.

## Author

[basicus](https://profiles.wordpress.org/basicus/)

## Links

- [Plugin page](https://devenia.com/plugins/devenia-mcp-updater/)
- [Download the stable plugin ZIP](https://downloads.devenia.com/devenia-mcp-updater.zip)
