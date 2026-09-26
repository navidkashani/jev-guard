# SpamLens

![SpamLens](.wordpress-org/banner-1544x500.png)

Spam protection for WordPress comments, product reviews, pingbacks and Contact Form 7, using the Jev decision model.

[![CI](https://github.com/veronalabs/spamlens/actions/workflows/tests.yml/badge.svg)](https://github.com/veronalabs/spamlens/actions/workflows/tests.yml)
[![License: GPL-2.0-or-later](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](LICENSE)

> **Not affiliated.** SpamLens is an independent plugin by VeronaLabs. It is not affiliated with, endorsed by or supported by TypeSafe AI, Vercel or OpenRouter. Jev and TypeSafe are marks of TypeSafe AI and are used only to indicate compatibility.

## What it does

- Comments the model is confident are spam go straight to the **Spam** folder.
- Borderline, on-topic-but-spammy and abusive comments are **held for moderation**.
- Everything else is left to WordPress' own rules. The plugin never approves a comment on its own.

If the service cannot be reached, the comment follows the normal WordPress flow and is re-checked automatically within 20 minutes.

## Requirements

- WordPress 6.4 or newer, PHP 7.4 or newer.
- An API key from [TypeSafe AI](https://console.typesafe.ai), [Vercel AI Gateway](https://vercel.com/ai-gateway) or [OpenRouter](https://openrouter.ai). You pay the provider; the plugin is free.

## Install

1. Download `spamlens-<version>.zip` from the [latest release](https://github.com/veronalabs/spamlens/releases/latest).
2. In WordPress go to **Plugins → Add New → Upload Plugin**, choose the zip and activate it.
3. Go to **Settings → SpamLens**, pick a provider and paste the API key.
4. Click **Test connection**. Optionally run the Calibration tool and adjust the thresholds.

GitHub's green "Download ZIP" button gives a folder named `spamlens-main`; WordPress would treat a later release zip as a second plugin. Use the release zip.

The key can also be defined in `wp-config.php`: `define( 'SPAMLENS_API_KEY', '...' );`

### Upgrading from Jev Guard

SpamLens is the new name of Jev Guard (1.0.0). Activating SpamLens switches Jev Guard off and brings over its settings, statistics, the scores and history stored on each comment, and the re-check queue. `JEV_GUARD_API_KEY` in `wp-config.php` is still read. Code that used the old names must move to the new ones: `jev_guard_*` hooks are `spamlens_*`, and classes are under `SpamLens\Service\…`.

## What is sent to the provider

Nothing is sent until you save an API key, and only the provider you selected receives data. For each submission that is not skipped by your settings the plugin sends:

- the submission text (capped at 6,000 characters), author name and website, and the number and hosts of links in it;
- the page it belongs to: title, type, tags and categories, and — depending on the **Page context** setting — the excerpt or the first 300 (1,200 on "Extended") characters and its H2/H3 headings;
- for replies, the first 200 characters of the parent comment;
- the site name, URL, language, your "About this site" notes and the author's number of previously approved comments;
- optionally (Settings → SpamLens → Privacy) the author email, IP address, user agent and referer.

The text of password-protected, private and unpublished pages is never sent, at any Page context level. The response contains probabilities only; the plugin stores those, the category and the model version as comment meta.

Provider privacy policies: [TypeSafe AI](https://typesafe.ai/privacy) · [Vercel](https://vercel.com/legal/privacy-policy) · [OpenRouter](https://openrouter.ai/privacy). The full disclosure is in [readme.txt](readme.txt) under "External services".

## Cost

Jev is priced per input token and a comment check uses roughly 500 tokens; as of September 2026 that is a fraction of a cent per check, and Vercel's free monthly credit covers a few hundred thousand checks. Prices and limits are set by the providers and change — see [TypeSafe AI](https://typesafe.ai), [Vercel AI Gateway pricing](https://vercel.com/docs/ai-gateway/pricing) and the [Jev page on OpenRouter](https://openrouter.ai/typesafe-ai/jev).

## Development

Requirements: PHP 7.4+, Composer 2, Node 22+.

```sh
composer install               # dev tools; WP Scoper writes packages/autoload.php (the autoloader the plugin loads)
npm install
npm run build                  # settings app (public/app), comments script (public/js) and styles (public/css)
npm run dev                    # rebuild on change
npm run typecheck && npm test  # TypeScript and Vitest (calibration maths)
composer phpcs                 # WordPress coding standards + PHPCompatibility
npm start                      # wp-env: WordPress + Contact Form 7 at http://localhost:8888
npm run test:php               # PHPUnit inside wp-env
bash bin/install-wp-tests.sh wordpress_test root '' 127.0.0.1 latest 6.1.7 && vendor/bin/phpunit   # PHPUnit without wp-env
composer dist                  # dist/spamlens-<version>.zip, the file that ships
```

### Structure

The plugin follows the VeronaLabs [Forge](https://github.com/veronalabs/forge) layout:

```
spamlens.php                 loads packages/autoload.php, src/constants.php, then Bootstrap::init()
src/Bootstrap.php             lifecycle hooks; runs the service providers on plugins_loaded
src/Container/                service container and providers (Core: classifier, integrations, privacy, REST; Admin: screens, assets, notices)
src/Service/Api/              HTTP client and provider presets
src/Service/Classifier/       builds the request, reads the answer (Verdict), Submission value object
src/Service/Integrations/     Comments, Contact Form 7, IntegrationManager
src/Service/Rest/             REST routes under spamlens/v1 used by the admin screens
src/Service/Admin/            Settings → SpamLens mount point, comments list column and meta box, notices
src/Service/Assets/           enqueues the built files from public/
resources/react/              settings app (React, TypeScript, Tailwind)
resources/entries/            comments-screen script
resources/scss/               comments-screen styles
resources/assets/             logos used at runtime
resources/languages/          translation template (spamlens.pot)
views/                        PHP templates
tests/                        PHPUnit (WordPress test library)
```

### Releasing

1. Bump the version in `spamlens.php`, `src/constants.php`, `package.json` and `readme.txt` (Stable tag), and add the changelog entries to `CHANGELOG.md` and `readme.txt`. CI fails if they differ.
2. Publish a GitHub release tagged `v<version>`. `.github/workflows/deploy.yml` runs the test suite, builds the zip, attaches it to the release and, once the `wordpress-org` environment has the `SVN_USERNAME` variable and `SVN_PASSWORD` secret, deploys to WordPress.org. Directory assets (banner, icon, screenshots) go in `.wordpress-org/`.

## Hooks

| Hook | Purpose |
|---|---|
| `spamlens_skip_comment` | Return a non-empty reason to skip the check for a comment, `''` to force it. |
| `spamlens_state` | Change the state (submission, page, site data) sent to the model. |
| `spamlens_post_context` | Change or empty the page context sent with a comment (e.g. paywalled posts). |
| `spamlens_questions` | Change the questions the model is asked. |
| `spamlens_decision_options` | Change thresholds and the link rule per submission. |
| `spamlens_decision` | Override the final tier: `spam`, `hold` or `allow`. |
| `spamlens_providers` | Add or change provider presets. |
| `spamlens_endpoint` / `spamlens_model` | Override the endpoint URL or model id. |
| `spamlens_request_args` | Change the `wp_remote_post()` arguments (timeout, headers). |
| `spamlens_integrations` | Register an adapter for another form plugin (implements `SpamLens\Service\Integrations\Integration`). |
| `spamlens_loaded` (action) | Runs after the plugin registered its services; receives the service container. |
| `spamlens_cf7_submission` | Change the submission built from a Contact Form 7 entry. |
| `spamlens_cf7_treat_hold_as_spam` | Treat "hold" verdicts on forms (which have no moderation queue) as spam. |
| `spamlens_cf7_verdict` (action) | Runs after a Contact Form 7 submission was classified. |
| `spamlens_retry_run` (action) | Runs after the retry queue processed a batch. |

Each hook is documented where it is called (`grep -rn apply_filters includes/`).

## More

- FAQ, language notes, model versions and calibration advice: [readme.txt](readme.txt)
- Changes: [CHANGELOG.md](CHANGELOG.md)
- Contributing: [CONTRIBUTING.md](CONTRIBUTING.md)
- Reporting a vulnerability: [SECURITY.md](SECURITY.md)
- License: [GPL-2.0-or-later](LICENSE)
