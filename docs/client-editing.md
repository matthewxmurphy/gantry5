# Administrator-approved client text

Client editing is off when no policy exists. It creates no users, roles or
capabilities. A settings administrator opens **Client text policy**, reviews the
existing native capabilities named there and explicitly confirms enabling the
mode and each approved instance. Native CMS login/admission still applies:

- WordPress: existing `edit_pages`; settings administration remains `manage_options`.
- Joomla: existing `core.edit` on `com_content`, with existing administrator login
  and component admission; settings administrators retain native template/Gantry
  `core.admin` authority.
- Grav: a fully authenticated account with `admin.login` and `admin.pages`;
  settings administrators additionally have `admin.plugins`.

Add the built-in **Client text** particle using ordinary Layout Manager and save
explicit text before approving it. Only non-inherited instances in stored version 2
layouts are eligible. No raw-content, URL, image, CSS, Twig or HTML field is exposed.
The renderer escapes HTML and encodes square brackets so WordPress's existing
downstream widget shortcode filter cannot evaluate submitted shortcode text.
Theme overrides of the renderer/blueprint are ineligible. Changing their canonical
definitions or escaper invalidates existing policy until a settings administrator
reviews and explicitly approves the current definitions.

The persistent per-theme policy is `config/client-editing.yaml`, outside caches.
Missing policy is disabled; empty, malformed, unsafe or obsolete policy fails
closed for non-settings administrators. Settings administrators retain access to
review/replace an invalid policy. While enabled, the shared dispatcher blocks all
legacy Gantry actions for restricted users. Their separate page lists only approved
text and exposes no layout, global settings or administrator navigation. Expanded
native entry is limited to this resource and bound to the native admission theme
and configuration root. A requested theme that disagrees with that context is
refused, rather than applying another theme's policy.

The new policy writer currently supports absolute POSIX filesystem paths and has
not been verified on Windows. An absent policy never invokes that writer/catalogue
and preserves existing native admission even when its configuration location is
unavailable or uses another filesystem convention. A known expanded client entry
never falls back to legacy access if its policy becomes unavailable.

Every save uses the native POST nonce, current policy revision and current whole
layout revision. The server rechecks native capability and current approvals, then
patches only the approved `content[instance].attributes.text`. Other parsed YAML
data is preserved; YAML formatting/comments may be normalized. It does not call
`Layout::save()`, change inheritance, move particles or save atoms/global settings.
Text is UTF-8, at most 4000 characters, and remains literal. Theme caches are cleared
to avoid stale rendered content.

A private per-theme lock coordinates policy and client saves; replacement is atomic
per file. Ordinary Layout Manager writes do not share that lock, so close concurrent
layout editors and reload after any policy/layout change. This is not a filesystem
transaction or a claim of multi-process compare-and-swap against arbitrary writers.

Acceptance requires actual native CMS role/nonce/route checks, default-off and
disable behaviour, settings-admin recovery, stale revisions, malformed policy,
unapproved/inherited/raw field refusal, complete parsed-layout preservation,
definition-change reapproval and literal rendering through real Twig/WordPress
shortcode consumers. Existing snapshot or compatibility tests do not prove these
client editing contracts.
