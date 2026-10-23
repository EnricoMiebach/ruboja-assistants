# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), versions follow
[Semantic Versioning](https://semver.org/). The section of a version becomes the
text of its GitHub release and of the "View details" dialog in WordPress.

## [Unreleased]

## [0.1.6] - 2026-10-23

### Fixed
- Deleting the plugin on a multisite network removes the API key and settings on every site, not only on the main site.

## [0.1.5] - 2026-10-23

### Added
- Experimental setting, off by default: also cut off Ruboja's greeting ("Schön, dass du da bist, Enrico!"). Applies only from a width of 640 px, because narrower windows wrap the greeting unpredictably; inline height should then be about 100 px lower.

### Changed
- Inline assistants no longer draw their own border and background, so they blend into the page.

## [0.1.4] - 2026-10-23

### Added
- Setting "Ruboja header" (on by default): hides Ruboja's header line with logo, assistant name and organization by cutting off the top 60 pixels of the chat.

### Changed
- Inline assistants now use exactly the configured height instead of at most 90 % of the screen height, so a long start form can be shown without scrolling. Ruboja does not report its content height, so the height must be set to fit (about 1,050 px for the long form of the Vorauszahlungs-Assistent).

## [0.1.3] - 2026-10-23

### Fixed
- The status "Starting the assistant …" stays visible until Ruboja has loaded, instead of an empty white window.

### Added
- Settings: hint to create the API key with a technical Ruboja user, because Ruboja greets visitors with the key creator's first name.
- With `SCRIPT_DEBUG`, asset URLs include the file time, so browsers never mix old and new front-end files during development.

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
