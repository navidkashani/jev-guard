# Contributing

Bug reports and pull requests are welcome. For a new feature or a behaviour change, please open an issue first so we can agree on the approach before you write code.

## Setup

```sh
composer install   # phpcs, phpunit
npm install        # wp-env
npm start          # WordPress + Contact Form 7 at http://localhost:8888 (needs Docker)
```

## Before you open a pull request

- `npm run test:php` (PHPUnit inside wp-env) and `composer phpcs` must pass. CI runs the same checks on PHP 7.4–8.5.
- Code follows the WordPress Coding Standards as configured in `phpcs.xml.dist`; `composer phpcbf` fixes most formatting.
- If you change user-facing strings, regenerate `languages/spamlens.pot`: `wp i18n make-pot . languages/spamlens.pot --exclude=tests,bin,vendor,node_modules`.
- Add a line under "Unreleased" in both `CHANGELOG.md` and the changelog section of `readme.txt`.

## Notes

- The CI matrix pins Contact Form 7 5.9.8 for the WordPress 6.4 cell because newer CF7 releases require WordPress 6.7.
- `Update URI` in `spamlens.php` points at this repository so WordPress does not look for updates on wordpress.org. It must be removed before any wordpress.org submission.
- Contributions are licensed under GPL-2.0-or-later, the same as the plugin.
