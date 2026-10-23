=== Ruboja Assistants ===
Contributors: siteware
Tags: ai, chat, assistant, chatbot, ruboja
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Embeds Ruboja AI assistants on your website, inline in the content or as a floating chat window.

== Description ==

Ruboja Assistants connects your WordPress site to your organization in Ruboja with an API key and shows the assistants you release on your pages. Visitors use the assistant exactly as it is set up in Ruboja: system prompt, knowledge, tools, fixed model and start form.

* Enter the API key once under Settings → Ruboja Assistants. It is stored encrypted and never sent to the browser.
* Release individual assistants for the website. Everything else stays private.
* Embed with the block "Ruboja Assistant" or the shortcode `[ruboja_assistant id="…"]`.
* Inline in the content or as a floating chat button, per page or site-wide.
* Privacy first: by default the assistant only loads after the visitor clicks "Start assistant".
* Optional conversation history for returning visitors and user attribution for intranets.
* Rate limit per visitor IP.

= How it works =

When a visitor starts an assistant, WordPress asks Ruboja for a one-time chat URL (POST /chat-sessions) and shows it in an iframe. The API key stays on your server.

= External service =

This plugin sends data to Ruboja (https://www.ruboja.de), operated by Siteware GmbH:

* When an editor saves the API key or refreshes the list: the API key, to list the accessible assistants.
* When a visitor starts an assistant: the assistant id, a random reference id and, only with user attribution enabled, the e-mail address of the logged-in WordPress user. The chat itself runs directly between the visitor's browser and Ruboja.

Ruboja terms and privacy policy: https://www.ruboja.de

== Installation ==

1. Upload the plugin zip under Plugins → Add New → Upload Plugin and activate it.
2. In Ruboja, create an API key under Settings → API keys. Choose "Optional" for user attribution.
3. In WordPress, go to Settings → Ruboja Assistants, paste the key and save.
4. Release the assistants you want to show and save again.
5. Add the block "Ruboja Assistant" to a page, or use the shortcode.

Alternatively define the key in wp-config.php:

`define( 'RUBOJA_ASSISTANTS_API_KEY', 'sk_live_…' );`

== Frequently Asked Questions ==

= Which assistants can I embed? =

All assistants the creator of the API key may use in Ruboja. Only those you release in the settings appear on the website.

= Shortcode attributes =

`id` (required), `mode` (inline or floating), `height` (pixels, inline only), `title`, `label` (button text, floating only), `color` (hex), `position` (right or left).

= Do visitors need a Ruboja account? =

No. Without user attribution the chat runs anonymously on the account of the key creator.

== Changelog ==

= 0.1.5 =
* Experimental setting to also cut off the Ruboja greeting (off by default, from 640 px width).
* Inline assistants no longer draw their own border and background.

= 0.1.4 =
* New setting to hide the Ruboja header line (logo, assistant name, organization), on by default.
* Inline assistants use exactly the configured height, so a long start form fits without scrolling.

= 0.1.3 =
* The status "Starting the assistant …" stays visible until Ruboja has loaded.
* Settings recommend creating the API key with a technical Ruboja user.

= 0.1.2 =
* "Check again" finds new releases right away.
* Compatibility data comes from the readme.txt of the new release.

= 0.1.1 =
* The settings page shows the installed version with a link to the release notes.

= 0.1.0 =
* First version: settings page, released assistants, block, shortcode, inline and floating display, GitHub updates.
