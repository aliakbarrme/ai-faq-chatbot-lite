=== AI FAQ Chatbot Lite ===
Contributors: aliakbarrme
Tags: chatbot, faq, ai, openai, chat
Requires at least: 5.8
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 2.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Chat widget that answers visitors from your FAQ, with optional OpenAI answers grounded in your content.

== Description ==

Built from my experience creating **Monkbot**, an AI chatbot that has handled 374+ visitor conversations for an Austrian monastery. This Lite version brings the same idea to any small business site.

* Modern floating chat widget, mobile friendly, matches your brand color
* Simple FAQ format: Q: line, A: line, blank line between entries
* No-API mode: smart keyword matching with light stemming, instant and free
* AI mode (optional): OpenAI answers only from your FAQ, in the visitor's language; the key stays on your server
* Talk to a human link when the bot doesn't know
* Suggested questions on open
* Per-visitor rate limit, sanitized input, REST endpoint, no data stored

== Installation ==

1. Upload the plugin ZIP via Plugins > Add New > Upload Plugin.
2. Activate it.
3. Open Settings → AI FAQ Chatbot.

== Changelog ==

= 2.1.1 =
* Default Gemini model is now gemini-3.5-flash-lite (older models are closed to new keys)
* If Google retires a model, the plugin switches to the replacement Google names

= 2.1.0 =
* Matches your website automatically: brand color from your buttons, light/dark from your background
* Choose the chat button icon: AI sparkle chat (new default), chat bubble, robot or your logo
* Understands more casual phrasing ("give me some portfolio")

= 2.0.0 =
* New premium chat design in a Shadow DOM, so themes can no longer break it
* Typing indicator, timestamps, quick replies, related questions, WhatsApp handoff, teaser bubble, dark theme
* Smarter offline matching: synonyms, Bangla/Banglish, typo tolerance, several Q: lines per answer
* Free AI providers: Google Gemini and Groq (plus OpenAI), with conversation memory

= 1.0.0 =
* First release.
