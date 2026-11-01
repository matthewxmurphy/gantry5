# Frontend navigation changes — Gantry 5.7

The existing Gantry frontend bundle now normalizes the default off-canvas trigger
as a named, keyboard-reachable button. Enter and Space open the drawer; focus
moves into its visible controls, Tab wraps within its current menu level, and
closing returns focus to the trigger. Escape closes a disclosed submenu before
closing its drawer. Nested menu links support Arrow Up/Down, Home and End.
Ordinary destination links retain their navigation behavior.

The implementation keeps drawer state and ARIA attributes consistent when open
and close actions overlap, replaces detached mutation observers, and registers
cancelable touch movement without relying on a browser-global event. Menu
breakpoints accept fractional rem/em values and unitless pixel values.

Back-to-top uses the document's actual scroll container. Reduced-motion settings
disable its smooth scrolling and the drawer's transform animation. CSS themes
still control colors, focus appearance, layout and transition styles.

Run the source-built browser checks using
[the frontend test instructions](../tests/frontend/README.md). They exercise the
actual generated bundle and Nucleus CSS, with separate regression and keyboard
phases. Native Helium/CMS checks are needed as well: its sliding submenu and CSS
visibility transitions differ from the selected-markup fixture.

These changes do not establish a complete accessibility conformance assessment,
screen-reader or physical-device acceptance, compatibility with every theme, or
coordination with third-party modal dialogs. The drawer does not advertise modal
semantics or make the rest of the document inert. Custom themes should preserve
Gantry's menu structure and provide visible focus indicators.

Copyright 2026 Matthew C. Murphy. New documentation is MIT; existing Gantry
licenses and notices remain unchanged.
