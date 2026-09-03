=== Alchemy Forms ===
Contributors: websitealchemy
Tags: forms, form builder, contact form, multi-step forms, entries
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 2.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Self-hosted WordPress form builder — drag-and-drop fields, per-form styling, multi-step forms with conditional logic, and an entries dashboard with CSV export.

== Description ==

Alchemy Forms is a lightweight, self-hosted form builder for the Website Alchemy client portfolio.

**Features**

* Drag-and-drop field builder: text, email, phone, URL, number, date, paragraph, dropdown, radio, checkbox, single checkbox, file upload, hidden fields, HTML content blocks, and step breaks
* Multi-step forms with a progress bar and Back/Next navigation
* Per-field conditional visibility and per-field placeholder text
* Per-form styling: the Style panel is organized by component (Title/Label/Inputs/Placeholder/Button/Steps/Container), each with its own colors and, where it renders text, its own independent font, weight, and size (curated Google Fonts)
* Entries dashboard with CSV export and email notification (any number of recipients supported) on each submission
* Optional email marketing sync on submission — Flodesk, AWeber, or Mailchimp, with field mapping and audience/list/segment assignment
* Optional Cloudflare Turnstile spam-challenge verification, configured once for the whole site
* One-click import from a Ninja Forms `.nff` export
* Spam honeypot and nonce-verified submissions

== Installation ==

Not distributed via WordPress.org. Download the latest release zip from the GitHub repository's Releases page and upload it via **Plugins → Add New → Upload Plugin**. After the first install, updates are checked against the GitHub repo directly and show up as a normal "Update available" notice on the Plugins page.

== Changelog ==

= 2.5.0 =
* Added a Mailchimp integration — a third Email Marketing tab alongside Flodesk and AWeber. Connect with an API key, pick an audience, map email/first/last name fields.

= 2.4.0 =
* The Style panel is redesigned around 7 component tabs — Title, Label, Inputs, Placeholder, Button, Steps, Container — and every tab now owns its own color(s) plus, where it renders text, its own independent Google Font, weight, and font size. Nothing is shared across tabs any more (previously Primary/Border/Muted colors and the Heading/Body fonts applied to several components at once, which made it unclear what a given setting actually affected).
* Added a "Title" tab for the main form heading, and a "Success message" section on the Container tab — both previously had no dedicated color/font controls at all.
* Forms saved before this update keep rendering exactly as they did — each new field falls back to its old shared equivalent until the form is next edited and saved, at which point it moves onto the new independent fields.

= 2.3.1 =
* The builder screen now busts any admin-theme/plugin max-width it inherits so it always uses the full browser width, instead of leaving empty space on the right on wide screens.
* Widened the Style and sidebar (Publish/Settings/Email Marketing/Usage) columns from 280px to 340px so tabs wrap less and dropdowns/color pickers have more room.

= 2.3.0 =
* Replaced the single "Font pairing" preset with independent Heading font, Body font, and Placeholder font pickers — each a dropdown of ~30 curated Google Fonts (plus two no-load system-font options) with its own font weight (Light through Extrabold). Forms saved under the old preset system keep rendering exactly as before until next edited and saved.
* The Style panel has a new "Typography" tab holding all of the above, moved out of the Colors tab.

= 2.2.0 =
* The Style panel is now tabbed (Colors/Label/Inputs/Button/Steps/Container) instead of one long scroll with section headers, matching the Email Marketing tabs.

= 2.1.0 =
* Added AWeber integration via OAuth2 — connect once under Alchemy Forms → Settings (Client ID/Secret + a "Connect to AWeber" button), then enable it per form with its own list picker and field mapping.
* Email Marketing is now Flodesk/AWeber tabs instead of one flat panel, now that there are two providers to keep separated.

= 2.0.0 =
* Added Cloudflare Turnstile spam-challenge integration. New global "Alchemy Forms → Settings" page holds the site key/secret key (one Turnstile site covers the whole domain); each form gets its own "Require Cloudflare Turnstile verification" checkbox once those keys are set.

= 1.9.2 =
* Fixed the multi-step Back button not picking up the Button section's colors — it was styled as a separate transparent/outlined secondary button; now matches Next/Submit exactly.

= 1.9.1 =
* Added a "Secondary text color" option (Colors section) for the "Step X of Y" label, file upload hint text, success message text, and the Back button's hover border — previously all hardcoded to one fixed color.

= 1.9.0 =
* Added a "Text color" option for the submit/Next button, previously hardcoded white.
* Added a "Step indicator color" (Steps section) for the progress bar and step titles on multi-step forms, previously tied to Primary color.
* The always-visible sidebar (Publish/Form Settings/Email Marketing/Usage) now has clearly bordered, distinctly-shaded boxes instead of relying on subtle default spacing.

= 1.8.1 =
* Added a "Placeholder font" option, independent of the Font pairing used for real input text — defaults to matching the body font, so nothing changes unless you pick something else.

= 1.8.0 =
* Added a "Drop shadow" toggle plus color/opacity/blur controls for the form container, replacing the previously fixed shadow.
* Added a "Placeholder text style" option (Normal/Italic) so placeholder hints can look visually distinct from real input text.
* Added a "Space above button" control for the gap between the fields and the submit button/nav row, previously a fixed 1.75rem.

= 1.7.1 =
* Added a loading spinner to the submit button while an AJAX submission is in progress.

= 1.7.0 =
* The Flodesk segment picker now fetches real segment names from your account instead of requiring segment IDs to be copied in by hand — click "Refresh segments from Flodesk" after entering an API key.
* Forms now submit via AJAX instead of a normal page POST — no more full page reload and scroll-to-top on every submission, whether it succeeds or shows a validation error.

= 1.6.0 =
* Added optional placeholder text for text-like fields (single line text, email, phone, website/URL, number, paragraph text). Purely supplementary — the field's label still always renders, including when "Hide label" is on.

= 1.5.0 =
* Added Style controls for submit button width (auto/full) and alignment, and container padding and border width — all previously fixed values.

= 1.4.1 =
* Publish, Form Settings, Email Marketing, and Usage are now always visible in a sidebar next to the Style panel, instead of hidden behind a "Settings" button.
* Fixed the plugin's listed URL (websitealchemy.co.nz → websitealchemy.com).

= 1.4.0 =
* Added an Email Marketing panel: sync submissions to a Flodesk audience via API key, with email/first name/last name field mapping and segment ID assignment.

= 1.3.0 =
* Added Single Checkbox and Hidden Field types, and taught the Ninja Forms importer to map onto them (including resolving common `{wp:...}` merge tags on hidden fields).

= 1.2.0 =
* The "Send submissions to" setting now accepts multiple email addresses, separated by commas, instead of exactly one.

= 1.1.2 =
* Fixed a bug where a leading, trailing, or consecutive page-break could leave a multi-step form's last step without a Submit button, or reopen the form on the wrong step after a validation error.
* Fixed a bug where a checkbox- or file-triggered condition, if imported from Ninja Forms, silently dropped the dependent field's answers on every submission.
* Fixed orphaned file uploads left behind in the Media Library when a submission with multiple file fields failed validation.
* Deduplicated the style defaults hardcoded in two places.

= 1.1.1 =
* First release as Alchemy Forms (renamed from WA Forms). Code review pass completed; GitHub-based automatic updates wired in.
