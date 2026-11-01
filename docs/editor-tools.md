# Section Styles and saved-site preview

In Layout Manager, a section receives a **Styles** tab only when its exact section
ID matches an installed styles blueprint whose type is `section`. Unmatched or
custom sections receive no fabricated style fields. The existing settings-management
permission protects both loading and saving these controls; native POST nonces remain
required.

The tab uses the existing Styles blueprint, outline overrides/defaults and named-block
save, event and CSS compilation. **Save section styles** saves immediately. It does
not apply section attributes, save Layout, add Layout history or discard a dirty draft.
The ordinary Apply and Apply & Save actions retain their existing meaning. Cancel
does not undo styles that were already saved; unsaved section attributes still follow
the normal Layout workflow.

Style controls belong to a separate sibling form, including dynamically inserted
controls. This avoids nested forms and prevents required style controls from blocking
ordinary Layout Apply. The standalone admin script sends only that form's enabled
overrides and never invokes the Layout save action. No compiled admin bundle change
is required for this feature.

**Saved site preview** opens a separate admin tab so the editor draft remains intact.
It uses the CMS's saved frontend home URL, not WordPress Customizer or an invented
outline-preview URL. The site's normal routing, active theme and assignments decide
what is shown; this may differ from the selected editor outline. Unsaved Layout and
section-input drafts are excluded.

Desktop 1280, Tablet 768 and Mobile 390 select fixed CSS viewport widths. Reload
navigates to the same saved URL. These are viewport sizes, not device emulation.
Only the platform-provided same-origin HTTP(S) URL is loaded; there is no arbitrary
URL field, proxy or CSP/framing-policy override. If site policy blocks the frame,
use the saved-site link. No page, assignment or theme is changed by preview.

Verify these features in an owned native CMS fixture: real section-blueprint match
and no-match cases; settings permission/nonce refusal; inherited overrides and actual
CSS output; required controls excluded from the Layout form; unchanged dirty Layout
and unapplied section attributes across style save/failure/Cancel; and actual saved
frontend frame content at each fixed width/reload. The standalone nullable-rewriter
and snapshot filesystem tests do not establish these editor/browser contracts.
