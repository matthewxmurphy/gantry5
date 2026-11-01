# Design packs (unreleased)

Design packs share reviewed visual definitions across outlines or compatible sites.
They do not install executable particles and do not duplicate a private outline.
Open **Design packs** from Gantry administration with the existing settings-management
permission. Every download, compatibility preview and import uses the platform's
normal POST token check. Merely opening the page changes no outlines or assets.

## Create and review

1. Supply a pack ID, version, title, author, license and full required notices.
2. Select current palette/font fields from the installed theme's list. Only checked
   `input.colorpicker` and `input.fonts` fields are read. Colors use bounded hex or
   `transparent`; font-family names cannot contain CSS functions or URLs.
3. Define sections, rows and placements in the displayed JSON. Section names must
   match actual installed section style blueprints. Rows total 100 percent. The
   initial supported placements are installed `logo`, `totop` and `system-content`.
   Adapt the example if the theme has different section names. This does not read
   or copy the current layout, its inheritance, content, atoms or CMS bindings.
4. Optionally author visual defaults: logo image, height, link toggle, target and
   class; to-top icon and class. Text, SVG, custom HTML, arbitrary links and API
   fields are refused. Imported instances explicitly clear inherited logo URL/SVG
   and private text; fixed accessible fallback labels remain.
5. Optionally select exact bitmap paths below this theme's writable `custom/images`
   directory. Each selection includes its complete license and notice. A logical
   `asset:ID` reference binds a selected image to a logo. Packaged theme, vendor and
   font files are never collected. No remote asset is fetched.
6. Acknowledge redistribution rights, download the JSON and review the complete
   document and image metadata before sharing it. Known visual field formats are
   a supported subset, not a blanket guarantee that arbitrary user values or image
   metadata are free of private information. Assets are not redacted or relicensed.

For example, the optional bitmap selection is:

```json
{"mark":{"path":"branding/mark.png","license":"Full applicable license text","notice":"Complete original attribution"}}
```

Use `{"logo":{"image":"asset:mark","height":"4rem","link":true}}` for its
visual defaults. Fields or types not installed in the destination are refused.

## Preview and import

Upload an original downloaded JSON. Schema 1 includes the pack identity and full
notices, minimum Gantry version, selected style values, structural layout, explicit
visual defaults and embedded bitmap bytes with MIME, dimensions and SHA-256.
Canonical JSON prevents ambiguous/duplicate-key documents; use the creation UI to
produce the supported encoding. Compatibility preview validates the entire pack
against current installed blueprints and actual image decoding before any write.
It shows field/section/type names, bitmap metadata and escaped full notices.

Choose the same JSON again and explicitly confirm import. The digest must match
the preview. Gantry rechecks current compatibility, requests a new random outline
name (Joomla supplies its native new template-style ID), copies images into a
fresh versioned namespace, and calls the existing
`OutlineCollection::create()` with native `LayoutReader` data. It saves selected
styles and a `design-pack.yaml` attribution record in that new outline only.
Particle defaults become explicit settings on the new visual placements.
An optional reservation callback runs after the native API chooses the final ID
and before its layout write, refusing any pre-existing directory, including orphan
folders. Existing callers without this option retain their normal creation flow.
Joomla's fresh template style has no home/menu assignment; a failed reservation
removes that new database record without touching the refused directory.
Bitmap references use the actual new custom-file path and Gantry's CMS URL API,
preserving the site's base path and Grav's writable data location rather than
assuming that the theme's image stream resolves to its custom directory. Custom
image storage outside the CMS public root is refused; the pack never creates a
public-file proxy. Native acceptance must verify actual image HTTP bytes.

The new outline is **unassigned**. Existing outlines, assets and page assignments
are unchanged. Review it and compile its styles using existing Styles controls
before assigning it. Import never selects a homepage or enables a theme. A failed
import removes only this request's reserved outline and newly created bitmap
namespace; no old outline or image is used as a rollback target. New empty parent
directories may remain. Simultaneous administrator filesystem edits are outside
the transaction guarantee.

## Supported bounds and preservation

- Maximum JSON: 2 MiB; maximum 80 visual style fields, 12 sections, 8 rows/section,
  4 placements/row and 40 placements total.
- Maximum 8 bitmaps, 512 KiB each and 1 MiB combined; PNG, JPEG and WebP only.
  MIME and dimensions must match the bytes; GD must actually decode the image.
  Each dimension is at most 4096 and total pixels at most 16 million.
- Only exact local custom paths are accepted. Absolute/traversal paths, links,
  hardlinks, case aliases, unsupported formats and filename collisions are refused.
  Fresh image directories and exclusive file opens prevent asset overwrite.
- Full supplied pack/asset license and notice text is retained; oversized notices
  are refused rather than truncated. This feature grants no redistribution rights.
- No Twig/PHP/blueprint installation, arbitrary CSS, font collection, custom HTML,
  live content, page atoms, menus, modules/widgets, account/API settings or mapping
  between different CMS module systems. Missing targets are refused, never guessed.

The separate [private snapshot](upgrade-assistant.md) preserves exact selected
outline YAML for same-outline rollback; it is not a shareable design pack.

## Verification

Run the new isolated real-filesystem/image checks under actual PHP 8.4 with GD:

```sh
php tests/php84/design-pack.php
```

Native CMS acceptance additionally needs real settings-admin/token requests,
preview without writes, import into a new unassigned outline, actual layout/style
reload, source/old-outline/asset/assignment preservation, attribution retention,
missing-type/field and malformed-file denial, and denied low-privilege requests.
These new checks do not require replaying the unrelated PHP compatibility suite.
