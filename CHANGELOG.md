# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), versions follow
[Semantic Versioning](https://semver.org/). The section of a version becomes the
text of its GitHub release and of the "View details" dialog in WordPress.

## [Unreleased]

## [0.1.2] - 2026-10-22

### Fixed
- "Check again" under Dashboard → Updates now finds a new release right away, even if the page was opened a moment before.
- Updates and "View details" use "Tested up to" and the requirements from the readme.txt of the new release, so WordPress 7.1.x no longer reports the plugin as untested.

## [0.1.1] - 2026-10-22

### Added
- The settings page shows the installed version with a link to the release notes on GitHub.

## [0.1.0] - 2026-10-22

### Added
- Settings page with encrypted API key, connection test and list of accessible assistants.
- Release flags per assistant, optionally only for logged-in users.
- Block "Ruboja Assistant" and shortcode `[ruboja_assistant]`, inline or floating.
- Site-wide floating assistant.
- Load-on-click notice, optional conversation history, user attribution by e-mail, rate limit.
- Updates from GitHub releases via the `Update URI` header, with release notes under "View details".
- German translation.
