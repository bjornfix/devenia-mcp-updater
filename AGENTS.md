# Devenia MCP Updater Agent Notes

## Accepted Plugin Check Exception

- The user has explicitly accepted that this plugin will not pass WordPress Plugin Check with literal zero errors and zero warnings while it provides the working private non-WordPress.org update channel.
- Still install the exact candidate on `dev.devenia.com` with WP-CLI and run the complete official Plugin Check command with low-severity warnings enabled.
- The accepted result is exactly 2 errors and 3 warnings, all at line/column 0:
  1. `ERROR plugin_updater_detected`: `Including An Update Checker / Changing Updates functionality. Plugin Updater detected. Use of the Update URI header is not allowed in plugins hosted on WordPress.org.`
  2. `ERROR plugin_updater_detected`: `Plugin Updater detected. These are not permitted in WordPress.org hosted plugins. Detected: site_transient_update_plugins`
  3. `WARNING update_modification_detected`: `Plugin Updater detected. Detected code which may be altering WordPress update routines. Detected: auto_update_plugin`
  4. `WARNING update_modification_detected`: `Plugin Updater detected. Detected code which may be altering WordPress update routines. Detected: pre_set_site_transient_update_plugins`
  5. `WARNING update_modification_detected`: `Plugin Updater detected. Detected code which may be altering WordPress update routines. Detected: _site_transient_update_plugins`
- Any different count, type, code, message, line, or column is release-blocking; do not generalize the exception from hook names or checker categories.
- Every other error or warning is release-blocking. Record the exact findings as an accepted updater exception; never call the result a 0/0 pass.
- Do not hide hook names, add suppressions/ignore lists, weaken the checker, or move scheduling to an external control plane merely to manufacture 0/0.
- This exception belongs only to `devenia-mcp-updater`. It never applies to a plugin distributed through WordPress.org or to another Devenia plugin.

## WordPress ZIP Contents

- WordPress ZIPs for this plugin must contain only installable WordPress plugin files.
- Include `devenia-mcp-updater.php` and `readme.txt`.
- Do not include GitHub/project Markdown files such as `README.md`, notes, reports, or architecture docs in the deployable WordPress ZIP or in production/dev plugin directories.
- Keep `README.md` in git for GitHub, but exclude it from WordPress packaging and deploys.
