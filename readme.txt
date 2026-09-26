=== SpamLens ===
Contributors: veronalabs, mostafa.s1990, kashani
Tags: spam, comments, antispam, contact form 7, ai
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Spam protection for comments, product reviews and Contact Form 7 powered by Jev, TypeSafe AI's decision model.

== Description ==

SpamLens sends each incoming comment, WooCommerce review, pingback and Contact Form 7 submission to Jev — a "System One" decision model by TypeSafe AI that returns calibrated probabilities instead of text — and acts on the answer:

* **Spam** (probability above the spam threshold) goes straight to the Spam folder.
* **Borderline** comments, comments that look like spam but clearly respond to the page, and abusive comments are **held for moderation**.
* Everything else is left to WordPress' own rules. SpamLens never approves a comment on its own.

If the service cannot be reached, the comment goes through the normal WordPress flow (or is held, your choice), and it is **re-checked automatically** within 20 minutes.

**Bring your own key.** Pick the service that hosts the model for you and paste its API key:

* **TypeSafe AI** (direct) — keys at console.typesafe.ai, currently about 1,200 requests per minute.
* **Vercel AI Gateway** — every Vercel team currently includes a $5 monthly credit, enough for roughly 240,000 comment checks. A payment method must be on file; the free tier is rate-limited, so bulk runs pause and resume.
* **OpenRouter** — pay-as-you-go.
* **Custom endpoint** — any proxy that accepts the TypeSafe request format.

Prices, credits and rate limits are set by the providers and were last checked in September 2026; see their pricing pages.

**Other features**

* "SpamLens" column in the comments list with the decision and probability ("Spam 99%", "Held 63%", "6% spam"), a human-readable category (SEO link spam, scam or phishing, commercial promotion, gibberish or bot, abusive, legitimate) and, when a comment was held for a reason other than its probability, that reason.
* **Re-check with SpamLens** row action and a **Check for Spam** button that sweeps the Pending queue in batches.
* **Calibration** tool: runs Jev on comments you already moderated and reports accuracy, false positives and the thresholds that would fit your site — read-only.
* History and raw answers in a meta box on the comment edit screen; false-positive / missed-spam counters based on your moderation.
* Privacy toggles for email, IP and user agent; suggested privacy-policy text; optional notice under the comment form.
* Filters for everything: `spamlens_state`, `spamlens_post_context`, `spamlens_questions`, `spamlens_decision`, `spamlens_skip_comment`, `spamlens_providers`, `spamlens_integrations` and more.

**Page context.** Jev judges a comment against the page it was posted on. By default it sees the title, type, tags and categories, the excerpt (or the first ~300 characters) and the page's headings. "Extended" sends the first ~1,200 characters — about four times as much page text — for sites where spam is on-topic and well written; "Title only" sends no text from the page at all, which is the right choice for paywalled or members-only content. Membership plugins keep paid posts published, so the plugin cannot tell them apart on its own: pick "Title only" or use the `spamlens_post_context` filter. The text of password-protected, private and unpublished pages is never sent at any level.

**Requirements**: a key from one of the services above. Comment checks run while the visitor waits (about 1–2 s, 5 s timeout by default).

SpamLens is an independent plugin by VeronaLabs and is not affiliated with, endorsed by or supported by TypeSafe AI, Vercel or OpenRouter. Jev and TypeSafe are marks of TypeSafe AI; they are used only to indicate compatibility.

= Source code =

The settings screen and the comments-screen script are built from the TypeScript and SCSS sources in the plugin's `resources/` folder (React, Vite, Tailwind CSS). The same sources, the build configuration and the tests are on GitHub: https://github.com/veronalabs/spamlens. To rebuild: `npm ci && npm run build`.

== External services ==

This plugin sends data to a third-party service to classify submissions. Nothing is sent until you save an API key, and only the service you select receives data.

**What is sent, and when:** for every new comment, review, pingback or Contact Form 7 submission that is not skipped by your settings, the plugin sends the submission text (capped at 6,000 characters), the author name and website, details of the page it belongs to — title, type, tags and categories, and, depending on the Page context setting, the excerpt or the first 300 (1,200 on "Extended") characters of its text and its H2/H3 headings; the text of password-protected, private and unpublished pages is never sent — for replies the first 200 characters of the parent comment, the number and hosts of links in the text, the site name, URL, language and the "About this site" notes you entered, and the author's number of previously approved comments. Optionally (Settings → SpamLens → Privacy) the author email address, IP address, browser user agent and referer are included. The Test connection and Calibration tools send the same kind of data on demand.

**Services** (one of them, as selected in the settings):

* TypeSafe AI — https://typesafe.ai — Terms: https://typesafe.ai/terms — Privacy: https://typesafe.ai/privacy
* Vercel AI Gateway — https://vercel.com/ai-gateway — Terms: https://vercel.com/legal/terms — Privacy: https://vercel.com/legal/privacy-policy
* OpenRouter — https://openrouter.ai — Terms: https://openrouter.ai/terms — Privacy: https://openrouter.ai/privacy
* A custom endpoint you configure yourself is governed by that provider's terms.

The response contains probabilities only. The plugin stores those probabilities, the category and the model version as comment meta; it does not store any additional personal data from the check.

== Languages ==

Jev is primarily trained on English, where it achieves its best accuracy. Other languages, including CJK scripts, are handled but not equally well. Default thresholds (spam 0.85, hold 0.50) were tuned on English comments. On a non-English site: describe the site and its languages in "About this site", run the Calibration tool before relying on the Spam folder, watch the false-positive counter, and consider raising the spam threshold so doubtful comments are held rather than spammed.

== Model versions ==

Providers alias the model id (`jev-latest`, `typesafe-ai/jev`, `typesafe/jev-latest`) to the current release. Every verdict stores the versioned model id that actually answered (for example `jev-1.13.0`). Once your thresholds are calibrated, put that versioned id in the Model field so a model upgrade cannot shift your scores; re-run the Calibration tool when you decide to move to a newer version.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/spamlens/` or install it from the Plugins screen.
2. Activate it.
3. Go to Settings → SpamLens, choose a provider, paste the API key and click "Test connection".
4. Optionally run the Calibration tool and adjust the thresholds.

You can also define the key in `wp-config.php`: `define( 'SPAMLENS_API_KEY', '...' );`

== Frequently Asked Questions ==

= Does it replace Akismet? =

It can run alongside it. When another plugin has already flagged a comment as spam, SpamLens skips its own check to save a request.

= What happens when the service is down? =

By default the comment goes through the normal WordPress flow and is re-checked automatically within 20 minutes (moved to Spam only if the re-check says spam). You can choose to hold such comments instead. Persistent authentication or credit problems show an admin notice.

= Does it slow down comment posting? =

The check runs synchronously and typically takes 1–2 seconds; it is capped by the timeout (5 s by default).

= How much does it cost? =

Jev is priced per input token (about $0.042 per million tokens at the time of writing); a comment check uses roughly 500 tokens. The plugin caches identical submissions for 10 minutes and skips moderators, disallowed-list matches and already-flagged comments.

= Which forms are supported? =

Comments (including WooCommerce reviews and pingbacks) and Contact Form 7. Other form plugins can be added through the `spamlens_integrations` filter.

== Screenshots ==

1. Overview: connection status and what SpamLens has checked, spammed and held.
2. Settings: pick a provider, paste the API key, choose or pin the model and test the connection.
3. Detection settings: spam and hold thresholds, abusive comments, "About this site" and page context.
4. The SpamLens column in the comments list: the decision and spam probability for every comment.
5. The Spam folder with the category Jev gave each comment.
6. Calibration: how Jev would have scored comments you already moderated, with suggested thresholds.
7. The SpamLens box on a comment: scores, model, history and Re-check.

== Changelog ==

= 1.1.0 =
* Renamed from Jev Guard to SpamLens. Settings and statistics from Jev Guard are copied on first activation. Developers: hooks are now `spamlens_*` and the wp-config constant is `SPAMLENS_API_KEY`.
* New settings screen with Overview, Settings and Calibration tabs.
* The settings screen, Re-check and Check for Spam now use the WordPress REST API instead of admin-ajax.
* An API key can now be removed from the settings screen.
* The setup notice can be dismissed without JavaScript.
* Rebuilt on the VeronaLabs plugin structure (service container, Composer autoloading through WP Scoper).
* Developers: classes moved to the `SpamLens\Service\…` namespaces; custom integrations now implement `SpamLens\Service\Integrations\Integration`.

= 1.0.0 =
* Initial release.
* Checks comments, WooCommerce reviews, pingbacks and Contact Form 7 submissions.
* Providers: TypeSafe AI, Vercel AI Gateway, OpenRouter and custom endpoints.
* Three-tier decision (spam / hold / allow) with hold rules for borderline, on-topic-but-spammy and abusive comments.
* Retry queue re-checks comments the service could not classify within 20 minutes.
* "Page context" setting (Title only / Standard / Extended) controls how much of the page is sent; `spamlens_post_context` filter.
* The text of password-protected, private and unpublished pages is never sent.
* A request the provider rejects as too large is retried once without the page text.
* Calibration tool, statistics, and a "SpamLens" column in the comments list with decision, category and hold reason.

== Upgrade Notice ==

= 1.1.0 =
Jev Guard is now SpamLens, with a new settings screen. Custom integrations must implement SpamLens\Service\Integrations\Integration.

= 1.0.0 =
Initial release.
