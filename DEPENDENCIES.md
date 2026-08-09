# Runtime dependencies

- [WordPress 6.8 or later](https://wordpress.org/documentation/wordpress-version/version-6-8/) provides the native plugin update system, scheduled checks, and filesystem update workflow.
- [PHP 7.4 or later](https://www.php.net/releases/7_4_0.php) runs the updater.
- [PHP Sodium extension](https://www.php.net/manual/en/book.sodium.php) verifies the signed update manifest before a package is accepted.
- [Devenia update channel](https://downloads.devenia.com/devenia-mcp-manifest.json) provides the signed manifest and content-addressed Devenia plugin packages.
