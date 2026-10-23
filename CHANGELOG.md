# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), versions follow
[Semantic Versioning](https://semver.org/). The section of a version becomes the
text of its GitHub release and of the "View details" dialog in WordPress.

## [Unreleased]

## [0.1.9] - 2026-10-23

### Added
- File check: each release carries checksums.json; once a day (and on "Check now") the plugin compares its files with the checksums of its version on GitHub and reports changed, missing and added files to the plugin developer by e-mail, and again when everything is back to normal. Result in Site Health and in a new "File check" card on the settings page. Prepared second channel: a Ruboja data source webhook.
- Releases now carry checksums.json next to the zip; `npm run downloads` lists the downloads per release.

## [0.1.8] - 2026-10-23

### Changed
- The Ruboja corporate design now applies to the back end instead of the front end: the settings page is laid out as Ruboja cards with the Ruboja mark, Outfit, red buttons, red checkboxes and a connection status badge; the block placeholder in the editor uses Outfit and the Ruboja card shape. Shortcodes in the assistant table are shortened with a tooltip, descriptions limited to two lines.
- The front end is back to the design of 0.1.6 (dark blue default accent, pill buttons, colored header of the floating window, the theme's font). Outfit is no longer loaded on the website.
- Checked radio buttons and checkbox/radio focus rings on the settings page use Ruboja red instead of WordPress' blue theme color.
- "Save Changes" is now at the very bottom of the settings page, below the embedding help.

## [0.1.7] - 2026-10-23

### Changed
- Design follows the Ruboja corporate design: Ruboja red #df252b as default accent (sites that kept the old dark blue default switch automatically), Outfit font stack, flat 9 px buttons with a darker hover, floating window as a white card with hairline border, 18 px corners and a plain white header with a red accent dot, muted text colors.

### Added
- Loading spinner in the accent color while the assistant starts.
- Outfit font bundled (Latin subset from @fontsource-variable/outfit 5.3.0, SIL OFL 1.1), served from the site itself as "Ruboja Outfit", so no third-party font service is contacted.

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
