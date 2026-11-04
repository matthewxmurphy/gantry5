# Shared theme changes

Candidate **6.0.2-rc.1**. PHP **8.5.x only**. This is the next source candidate;
the published 6.0.1 tag and packages remain immutable. Staging acceptance is
recorded separately on task 342 and its draft PR.

Developer: Matthew X Murphy, https://MatthewxMurphy.com.
Support: https://builtnotbegged.com. Existing Gantry/Joomla licenses and
third-party notices remain applicable.

## One mutation boundary

`Gantry\Component\Changes\ChangeEngine` owns the theme lock, revision checks,
preview scope, save journal, failure recovery and undo. `NativeWriter` supplies
the canonical layout and configuration writers. Native administrator handlers
and the REST adapter reach these same services. Native CMS authentication,
permissions and form nonces remain authoritative; this engine grants no new
capability. A future MCP transport must call this boundary rather than introduce
another persistence path. No MCP endpoint is implemented by this candidate.
Installed CMS acceptance in this candidate targets Joomla only. WordPress
mutations fail closed until its native database adapter is supplied; neither
WordPress nor Grav runtime acceptance is claimed here.

General native saves and previews now require `platform.settings.manage`, the
same settings-administrator boundary as REST (`core.admin` on `com_templates`
or `com_gantry5` for Joomla). Historical module-manager JSON admission does not
authorize theme writes. Existing finer outline action checks also remain. The
exact approved-client text adapter retains its separate explicit policy and
content-editing checks; it grants no general theme or undo access.

The covered administrator mutations are outline create/duplicate/rename/delete,
layout, styles, page/particle settings, approved client text/policy, design-pack
application and snapshot restore. Legacy whole-theme ZIP import is refused by
this candidate; snapshot restore retains its bounded validation and confirmation
rules.
Menus, assignments and Grav position management remain outside this slice.
Joomla supplies the native database adapter; WordPress writes fail closed in
this candidate until its native database adapter and runtime gates are built.
This does not disable read-only WordPress administration.
They are not exposed by the REST change routes.
Legacy Joomla style aliases require an explicit migration before editing; a
read does not silently copy configuration or rewrite style bindings. This
candidate does not supply an automatic legacy migration utility.

Layout/index reads no longer save missing/stale indexes. Joomla catalogue
reads no longer copy legacy/external style aliases; migrate those styles
explicitly before admitting the theme to this modern candidate. Existing
properly bound native style IDs are unchanged.

The revision is an opaque 64-character lowercase hexadecimal value for the
**whole selected theme**, combining authoritative content/native metadata with
a unique committed generation. A base, inherited or sibling-outline change can
invalidate another open editor. Undo restores prior content under a new
generation, so an old revision cannot become valid again when bytes match.
Even a successful no-op save advances the generation. Reuse the editor's loaded
revision; do not silently fetch a newer revision immediately before saving.

Native forms carry `g6_revision`. Supported no-write planning uses
`g6_mode=preview`; normal saves and accepted AJAX responses return the new
revision. **Theme changes** lists recent summaries and offers explicit undo for
the current latest change. Other open editors must reload afterward.

## Joomla REST additions

All paths follow the existing Joomla `/api/index.php` entrypoint. The same
existing `X-Joomla-Token` header and native administrator ACL apply to all twelve
operations: the original nine outline/layout/settings operations plus these
three. The ACL requires `core.admin` on `com_templates` or `com_gantry5`.
Unauthenticated requests must return 401 before resource lookup; insufficient
permissions return 403. Basic authentication stays disabled. Gantry does not
issue credentials or tokens.

| Method | Path | Result |
|---|---|---|
| GET | `/v1/gantry/themes/{theme}/changes` | Current theme revision and at most 20 private-safe history summaries. |
| POST | `/v1/gantry/themes/{theme}/changes/preview` | Validate a supported change and return its plan without persisting it. |
| POST | `/v1/gantry/themes/{theme}/changes/undo` | Undo the current latest change with a matching revision. |

History and change responses use Joomla JSON:API, with type `gantry-changes`, an
`id`, and `attributes`. Preview attributes contain `theme`, `revision`,
`preview: true` and the proposed `result`. History attributes contain `theme`,
`revision` and `items`; each item has `id`, `label`, UTC `created` and `can_undo`.
Undo attributes contain `theme`, the new `revision`, a new `change_id`, and
`result.undone`. Snapshots and raw previous configuration are never returned.

Successful REST mutations return `X-Gantry-Revision` and `X-Gantry-Change`
headers. A DELETE still returns an empty 204 response, so retain its headers if
undo is needed. Successful read/preview responses carrying a revision also set
`X-Gantry-Revision`. API responses use `Cache-Control: no-store`.

Preview request, using a current revision from a resource GET or change history:

```json
{
  "revision": "<current theme revision>",
  "operation": "settings",
  "outline": "<owned outline id>",
  "data": {"styles": {"accent": {"color-1": "#2c759a"}}}
}
```

Supported preview operations are `create`, `rename`, `delete`, `layout` and
`settings`. `outline` is required except for create. `data` contains the same
fields as the corresponding write, without requiring a nested revision:
create uses `title` and optional installed `preset`; rename uses `title`; delete
uses an empty object; layout uses the native `layout` list and optional native
`preset`; settings uses selected `styles`, `page` or `particles` maps.

A preview is a validated data plan, **not a rendered page preview**, CSS compile
or reservation. It invokes no writer, save events or CSS compiler. The existing
saved-page preview remains separate. Submit the actual layout/settings/rename
write with that revision to commit; the server validates again. Another save
between preview and commit produces a revision conflict. Outline creation
allocates its native identity only during the locked commit; it remains
unassigned. `POST /outlines` accepts an optional top-level `revision`: include the
preview revision to enforce that precondition. Omitting it preserves the 6.0.1
create-body contract and captures the current server revision, rechecked under the theme lock;
that omission does not bind creation to an earlier preview.

Undo request:

```json
{"revision": "<current theme revision>", "change_id": "<latest change id>"}
```

Undo only accepts the current latest change. Repeated undo of the same change,
stale revisions and conflicting assignments are refused with 409. An undo is
itself a new journaled change; its returned revision must replace the old one.
Malformed input uses 400/422; unknown or corrupt snapshots use 409. Interrupted
or unrecoverable state uses `recovery_required` and blocks further edits. Inspect
private logs and state before retrying a 500/503 response.

## Persistence, retention and recovery

For writable `custom/config`, durable records live beside it in
`custom/.gantry-changes`; the lock is `custom/.gantry-changes.lock`. This location
survives normal derived-cache clearing. The journal directory is mode 0700 and
records are mode 0600, named with opaque change IDs and guarded by
`<?php exit; ?>`. It contains private page/configuration snapshots and must be
excluded from source commits, distributable packages and public backups.
Do not serve its bytes through static-file aliases or misconfigured PHP
handlers. Acceptance includes an unauthenticated request to a known record.

Retention keeps at most 20 changes, ordered by recorded UTC timestamp/ID while
always retaining the active generation, regardless of filesystem mtime or
clock adjustment. `generation.php` prevents revision replay. A durable
`pending.php` marks work whose commit/finalization did not finish. Neither
clearing caches nor deleting the marker establishes recovery.

Snapshots cover exact writable configuration files, exclusively owned
design-pack bitmaps, the two Joomla position manifest/translation files and
native Joomla template-style metadata. Menu assignment metadata is a revision
and undo precondition; the engine never restores or reassigns menus.
Native database work uses the CMS transaction. A failed save recovers original
authoritative files and invalidates/rebuilds derived configuration/CSS. Undo
checks current assignments before restoring native outline metadata. It does
not reassign menus or remove a protected default/assigned outline.

This is recoverable multi-file I/O. Readers do not yet receive an atomically
activated complete render bundle. Third-party event handlers can send messages,
write external files or perform other effects outside the filesystem/database
recovery boundary; those effects cannot be undone by this engine. The engine
does not claim distributed transactions or arbitrary extension rollback.

For interrupted state, stop mutation attempts. Preserve a private copy of the
journal, affected files, current database and logs. An operator must verify the
pending snapshot against the last known good site backup, restore matching
authoritative files and native database metadata, rebuild derived data, and
verify API/admin/front-end agreement before clearing the block. There is no
automatic retry, blind marker deletion or unverified recovery handover.

## Verification

Run with actual PHP 8.5 and the installed native Composer dependencies:

```sh
php tests/php85/change-engine.php platforms/joomla/lib_gantry5/vendor/autoload.php .
```

The local suite passes **76 checks on PHP 8.5.3**. It uses real Gantry
containers, native YAML writes and independent PHP processes. Coverage includes
no-write previews, exact-byte undo/recovery, same-size/same-mtime content,
stale and repeated undo, lock contention, retention after clock skew, corrupted
records, guarded/private history, linked paths, failed atomic activation, and a
SIGKILL during a real partial write followed by fresh-engine refusal. It also
executes an actual guarded journal containing a PHP-tag-bearing source filename,
verifies zero output/parse errors and restores the exact filename through undo.

These temporary filesystem fixtures do not certify native Joomla database
recovery, CSS compilation, token permissions or browser behavior. The staging
gate additionally requires all twelve unauthenticated operations to return 401,
existing-token success, preview preserving current output, API to administrator
to real-content front-end round-trips, stale open-editor refusal, native undo,
database/content preservation and zero new PHP warnings/fatals. Source checks
alone do not mark the candidate accepted.

The native API/cache suite additionally passes **57 checks**, including 12
checks that use the shipped Hydrogen preset to read missing/stale layout indexes
without changing source YAML bytes, mtimes, modes or paths. This verifies those
specific `Layout::index`/`Layout::load` entry points, not all legacy CMS GET-side
materialization. The administrator permission suite passes **54 fixture
checks**; its simulated platform identity is not acceptance with a second live
Joomla token. Both were run with the native vendors on PHP 8.5.3.
The native Format2 identity suite passes **52 checks on PHP 8.5.3**: implicit
grid/block and repeated-particle IDs are deterministic, explicit identities are
reserved before traversal, and independent processes read the same document
without random allocation. Attribute edits retain identity. After structural
editing/canonical serialization, clients should use the returned saved layout.

The optional real Joomla Database SQLite fixture passes **22 checks on PHP
8.5.3** for the native adapter's reference arguments, NULL restoration,
transactions and protected metadata. It uses actual Joomla dependencies and an
isolated memory database, with no mocked database behavior. One existing Joomla
SQLite bootstrap deprecation is narrowly counted and reported; other diagnostics
throw. The staging MariaDB metadata/undo pass remains the runtime authority.
