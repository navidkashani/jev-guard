# Jev Guard

Spam protection for WordPress comments, product reviews, pingbacks and Contact Form 7, using the Jev decision model.

[![CI](https://github.com/navidkashani/jev-guard/actions/workflows/ci.yml/badge.svg)](https://github.com/navidkashani/jev-guard/actions/workflows/ci.yml)
[![License: GPL-2.0-or-later](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](LICENSE)

> **Not affiliated.** Jev Guard is an independent plugin by Navid Kashani. It is not affiliated with, endorsed by or supported by TypeSafe AI, Vercel or OpenRouter. Jev and TypeSafe are marks of TypeSafe AI and are used only to indicate compatibility.

## What it does

- Comments the model is confident are spam go straight to the **Spam** folder.
- Borderline, on-topic-but-spammy and abusive comments are **held for moderation**.
- Everything else is left to WordPress' own rules. The plugin never approves a comment on its own.

If the service cannot be reached, the comment follows the normal WordPress flow and is re-checked automatically within 20 minutes.

## Requirements

- WordPress 6.4 or newer, PHP 7.4 or newer.
- An API key from [TypeSafe AI](https://console.typesafe.ai), [Vercel AI Gateway](https://vercel.com/ai-gateway) or [OpenRouter](https://openrouter.ai). You pay the provider; the plugin is free.

## Install

1. Download `jev-guard-<version>.zip` from the [latest release](https://github.com/navidkashani/jev-guard/releases/latest).
2. In WordPress go to **Plugins → Add New → Upload Plugin**, choose the zip and activate it.
3. Go to **Settings → Jev Guard**, pick a provider and paste the API key.
4. Click **Test connection**. Optionally run the Calibration tool and adjust the thresholds.

GitHub's green "Download ZIP" button gives a folder named `jev-guard-main`; WordPress would treat a later release zip as a second plugin. Use the release zip.

The key can also be defined in `wp-config.php`: `define( 'JEV_GUARD_API_KEY', '...' );`

## What is sent to the provider

Nothing is sent until you save an API key, and only the provider you selected receives data. For each submission that is not skipped by your settings the plugin sends:

- the submission text (capped at 6,000 characters), author name and website, and the number and hosts of links in it;
- the page it belongs to: title, type, tags and categories, and — depending on the **Page context** setting — the excerpt or the first 300 (1,200 on "Extended") characters and its H2/H3 headings;
- for replies, the first 200 characters of the parent comment;
- the site name, URL, language, your "About this site" notes and the author's number of previously approved comments;
- optionally (Settings → Jev Guard → Privacy) the author email, IP address, user agent and referer.

The text of password-protected, private and unpublished pages is never sent, at any Page context level. The response contains probabilities only; the plugin stores those, the category and the model version as comment meta.

Provider privacy policies: [TypeSafe AI](https://typesafe.ai/privacy) · [Vercel](https://vercel.com/legal/privacy-policy) · [OpenRouter](https://openrouter.ai/privacy). The full disclosure is in [readme.txt](readme.txt) under "External services".

## Cost

Jev is priced per input token and a comment check uses roughly 500 tokens; as of September 2026 that is a fraction of a cent per check, and Vercel's free monthly credit covers a few hundred thousand checks. Prices and limits are set by the providers and change — see [TypeSafe AI](https://typesafe.ai), [Vercel AI Gateway pricing](https://vercel.com/docs/ai-gateway/pricing) and the [Jev page on OpenRouter](https://openrouter.ai/typesafe-ai/jev).

## Development

```sh
npm install                    # wp-env
npm start                      # WordPress + Contact Form 7 at http://localhost:8888
npm run test:php               # PHPUnit inside wp-env
composer phpcs                 # WordPress coding standards + PHPCompatibility
bash bin/install-wp-tests.sh wordpress_test root '' 127.0.0.1 latest 6.1.7 && vendor/bin/phpunit   # without wp-env (needs a local MySQL/MariaDB)
```

Run `composer install` first for `phpcs` and `phpunit`.

## Hooks

| Hook | Purpose |
|---|---|
| `jev_guard_skip_comment` | Return a non-empty reason to skip the check for a comment, `''` to force it. |
| `jev_guard_state` | Change the state (submission, page, site data) sent to the model. |
| `jev_guard_post_context` | Change or empty the page context sent with a comment (e.g. paywalled posts). |
| `jev_guard_questions` | Change the questions the model is asked. |
| `jev_guard_decision_options` | Change thresholds and the link rule per submission. |
| `jev_guard_decision` | Override the final tier: `spam`, `hold` or `allow`. |
| `jev_guard_providers` | Add or change provider presets. |
| `jev_guard_endpoint` / `jev_guard_model` | Override the endpoint URL or model id. |
| `jev_guard_request_args` | Change the `wp_remote_post()` arguments (timeout, headers). |
| `jev_guard_integrations` | Register an adapter for another form plugin. |
| `jev_guard_cf7_submission` | Change the submission built from a Contact Form 7 entry. |
| `jev_guard_cf7_treat_hold_as_spam` | Treat "hold" verdicts on forms (which have no moderation queue) as spam. |
| `jev_guard_cf7_verdict` (action) | Runs after a Contact Form 7 submission was classified. |
| `jev_guard_retry_run` (action) | Runs after the retry queue processed a batch. |

Each hook is documented where it is called (`grep -rn apply_filters includes/`).

## More

- FAQ, language notes, model versions and calibration advice: [readme.txt](readme.txt)
- Changes: [CHANGELOG.md](CHANGELOG.md)
- Contributing: [CONTRIBUTING.md](CONTRIBUTING.md)
- Reporting a vulnerability: [SECURITY.md](SECURITY.md)
- License: [GPL-2.0-or-later](LICENSE)
