# Devenia MCP Updater Agent Notes

## WordPress ZIP Contents

- WordPress ZIPs for this plugin must contain only installable WordPress plugin files.
- Include `devenia-mcp-updater.php` and `readme.txt`.
- Do not include GitHub/project Markdown files such as `README.md`, notes, reports, or architecture docs in the deployable WordPress ZIP or in production/dev plugin directories.
- Keep `README.md` in git for GitHub, but exclude it from WordPress packaging and deploys.
