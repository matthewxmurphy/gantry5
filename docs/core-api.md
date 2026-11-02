# Gantry 6.0.1 core API

Developer: Matthew X Murphy, https://MatthewxMurphy.com. Support:
https://builtnotbegged.com. Existing Gantry/Joomla license terms apply.

The existing Gantry system plugin registers nonpublic routes with Joomla 6.2
Web Services; the existing Gantry component supplies its API client. Joomla's
API application authenticates the existing core token before dispatch. Gantry
then checks native settings-administrator permissions and delegates to the
same outline, layout and blueprint configuration stores as its admin UI.
There is no separate bridge plugin, standalone component or authentication
provider. Basic authentication stays disabled. PHP 8.5.x is required.

Open **Gantry administration > API / Swagger**. Its OpenAPI JSON export is
available to settings administrators. Swagger UI 5.34.1 is bundled locally;
the external validator is disabled and credentials are not persisted across
reloads. Requests must remain on the same CMS origin. On other CMS ports the
same entry shows this contract as a reference with request execution disabled;
Gantry 6.0.1 exposes no WordPress or Grav API transport.

All paths below follow `/api/index.php` relative to the Joomla installation.
`{theme}` is an installed Gantry theme identifier; `{outline}` is its outline ID.

| Method | Path after `/v1/gantry/themes/{theme}` | Behavior |
|---|---|---|
| GET | `/outlines` | List theme outlines. |
| POST | `/outlines` | Create a new unassigned outline from an installed preset. |
| GET | `/outlines/{outline}` | Read metadata and its current revision. |
| PATCH | `/outlines/{outline}` | Rename with a current revision. |
| DELETE | `/outlines/{outline}` | Delete an unassigned non-system outline with a current revision. |
| GET | `/outlines/{outline}/layout` | Read the native editor node list, preset metadata and revision. |
| PUT | `/outlines/{outline}/layout` | Validate and save the native layout document. |
| GET | `/outlines/{outline}/settings` | Read theme styles, page and particle settings. |
| PATCH | `/outlines/{outline}/settings` | Save selected validated theme setting groups. |

Use an existing authorized Joomla user's token in `X-Joomla-Token`.
Gantry never issues or resets it. Keep it out of source, URL parameters and
reports. The shell variable in these examples must be populated privately
from your existing credential store, without echoing it.

```sh
curl --fail-with-body "$CMS/api/index.php/v1/gantry/themes/$THEME/outlines" \
  -H "X-Joomla-Token: $JOOMLA_API_TOKEN" -H 'Accept: application/vnd.api+json'
curl --fail-with-body "$CMS/api/index.php/v1/gantry/themes/$THEME/outlines" \
  -H "X-Joomla-Token: $JOOMLA_API_TOKEN" -H 'Content-Type: application/json' \
  --data '{"title":"API outline"}'
```

Responses use Joomla JSON:API: a resource is in `data`, with `type`, `id` and
`attributes`; collections have a `data` array. Preserve the returned native
layout/preset documents and modify only intended nodes. New outlines are
unassigned and do not change the site's default template or menu assignments.
The API does not expose arbitrary files, template/PHP code, accounts, tokens,
menus or extension management.

Create accepts `title` and optional installed `preset`. Rename accepts `title`
and `revision`. Delete accepts a JSON body containing `revision`. Layout PUT
accepts `layout` (native node list), optional `preset` (native metadata object),
and `revision`. Settings PATCH accepts one or more of `styles`, `page`,
`particles`, and `revision`. Unknown top-level properties are rejected. Consult
the shipped OpenAPI JSON for the exact schema and server path.

Read the relevant resource immediately before a write. Send its opaque
`revision`; a stale revision returns 409. Retrieve and review again before
retrying. These synchronous operations have no asynchronous jobs or automatic
retry queue. There is no new Gantry-specific rate limiter; existing host and
Joomla limits apply. Standard server/Joomla logs can record method, route,
status and user identity. Do not log request authorization headers.

Expected errors:401 missing/invalid core token;403 native permission denied;
404 unknown installed theme/outline;400 malformed request;409 stale revision
or protected outline;422 validation failure;500 unexpected operation failure.
Treat 500 as a failed operation and inspect private logs before retrying.
Existing/default/system/assigned outlines are protected from API deletion.

Acceptance must exercise the installed staging package: API-created outline,
layout and style changes, exact admin values and front-end rendering, followed
by UI saves and PHP log review. Unit checks alone do not meet that bar.

Concurrency limits: the API lock serializes API writers and revision checks
catch completed native admin edits. Native admin writers do not share that
lock. Layout saves and their dependent outlines/events retain native multi-file
I/O behavior, without a database/filesystem transaction; an I/O or extension
event failure may need recovery from the site backup. Inspect current state
and private logs after500 before retrying.
