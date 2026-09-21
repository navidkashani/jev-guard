# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

## [1.0.0] - 2026-09-21

### Added

- Checks comments, WooCommerce reviews, pingbacks and Contact Form 7 submissions.
- Providers: TypeSafe AI, Vercel AI Gateway, OpenRouter and custom endpoints.
- Three-tier decision (spam / hold / allow) with hold rules for borderline, on-topic-but-spammy and abusive comments.
- Retry queue re-checks comments the service could not classify within 20 minutes.
- "Page context" setting (Title only / Standard / Extended) controls how much of the page is sent; `jev_guard_post_context` filter.
- The text of password-protected, private and unpublished pages is never sent.
- A request the provider rejects as too large is retried once without the page text.
- Calibration tool, statistics, and a "Jev" column in the comments list with decision, category and hold reason.

[Unreleased]: https://github.com/navidkashani/jev-guard/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/navidkashani/jev-guard/releases/tag/v1.0.0
