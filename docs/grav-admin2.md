# Grav2 native administration (unreleased)

The 6.0 candidate uses Grav2's API and Admin2 extension points. It does not install
the unsupported classic Admin plugin, create a substitute host Admin object or
replace Grav's Twig, Symfony or Toolbox vendor trees. Admin2 discovers the Gantry
page through `onApiSidebarItems` and `onApiPluginPageInfo`; the page's web component
is served from `admin-next/pages/gantry5.js`.

The Gantry-owned API router invokes the existing Layout, Styles, Upgrade,
Design packs, Client text and saved-preview controllers in an authenticated native
request context. This is an adapter for the existing implementation, not a second
snapshot, pack or layout engine. Native CMS acceptance remains required; a working
Admin2 shell or a resolved dependency lock alone does not prove these features.

## Authority

Every request enters the host's real API middleware and permission checks. Theme
administration requires `api.config.themes`, approved text requires
`api.pages.update` and a currently enabled administrator-approved policy, and
package actions additionally require `api.gpm.write`. Menu actions also require
page-update permission. API-key scope caps remain enforced by the native permission
checker. Unknown Gantry authorization actions are denied in the API context.
No capabilities, roles or users are changed.

The context also requires the native user's fully authenticated and authorized
flags. Scoped API keys for which Grav deliberately leaves those flags unset are
refused with HTTP 403; this adapter does not manufacture those flags or promise
Gantry actions for such keys. Admin2's native authenticated session/token flow is
the editing interface.

Grav2's permission namespace differs from classic administration. Its Client text
authority label is included in the policy definition revision, so an old policy
must be explicitly reviewed and approved for the new native authority. The
existing default-off, stale-revision and denied-legacy-route rules remain in force.

## Transport

The component calls `POST /api/v1/gantry/dispatch` (using the site's configured API
prefix) through the native API client. Its descriptor contains `method`, a relative
Gantry `path`, a `query` object and URL-encoded `fields`. Genuine file uploads use
`FormData`, with the same descriptor in `_gantry_request`; PHP's real upload
handling and the existing `is_uploaded_file()` checks remain intact.

The response's `data` contains the inner `status`, allowed response `headers`,
`body`, `encoding`, `base_url`, `asset_base` and `bridge_url`. Ordinary bodies use
UTF-8; private attachments use base64 with their original MIME/disposition and
private/no-store headers. No session cookie or authentication header is returned.
Snapshot and pack downloads still require their existing explicit acknowledgements.

The iframe transport binds its source window, document channel, same origin and
Gantry's fixed virtual path prefix. It uses native header authentication, never
URL tokens or saved authentication state. The API's normal cookie-origin/CSRF
protection applies to the actual outer POST. The standalone document loads local
Gantry assets and Grav's installed jQuery; it does not rely on absent classic
Admin chrome. Other installed themes must be activated through native CMS controls
before using their editor; this bridge edits the active Gantry theme.

Official extension contracts: [Grav API developer guide](https://learn.getgrav.org/2/api/developer-guide).
