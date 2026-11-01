# Upgrade assistant

Open **Upgrade assistant** in Gantry administration for the selected outline.
The existing CMS settings permission and native POST nonce protect the feature.
Preflight reports the current local PHP requirement, installed versions and
configured particle types that are unavailable under current dependency checks.
It does not certify another release or third-party code.

An untouched outline may have no saved custom configuration directory. Preflight
still works and reports zero files; snapshot/restore actions are unavailable until
custom outline settings exist. GET creates no directory and never substitutes the
packaged theme configuration for a private custom-settings backup.
Particle inspection uses the non-saving layout-index API; when no custom settings
exist, the unavailable inspection is labelled instead of initializing a layout.

Downloading requires acknowledging that the snapshot contains private settings,
possibly including embedded credentials and content. The attachment contains only
the selected outline's YAML files, their exact bytes and integrity digests. No
snapshot is stored in a public directory or server cache. Keep the download private;
it is not a complete site backup. CMS/service configuration, accounts, PHP, assets,
other outlines and non-YAML files are outside this operation.

Upload the unmodified JSON to preview file paths and create/replace/unchanged counts.
Values are never shown. To restore, select the same file again and confirm. The
current outline must still match the preview; otherwise preview again. Close other
configuration editors first: the assistant coordinates its own restores, while
ordinary Gantry editors do not share its lock. Unlisted files are preserved.

Restoration validates the complete bounded payload and YAML before writing, stages
files next to their targets, uses atomic per-file replacement and keeps the previous
bytes in memory for compensation if a later write fails. This is not a filesystem
transaction or protection against process termination/power loss. Keep the private
download for manual recovery. After success Gantry's theme cache is cleared; use the
existing Styles controls to recompile CSS and review the site before upgrading.

Limits: 128 YAML files, 256 KiB per file, 1 MiB decoded total and 2 MiB JSON upload.
The versioned canonical JSON format rejects ambiguous/duplicate keys, other scopes,
paths outside the outline, symlinks/hardlinks, case-ambiguous paths, non-YAML paths,
corrupt digests and invalid YAML.
Restore does not evaluate embedded HTML, Twig or PHP.

Run the new filesystem acceptance with actual PHP 8.4 and installed YAML dependencies:

```sh
php tests/php84/upgrade-snapshot.php /path/to/installed/vendor/autoload.php
```

It uses only a new owned temporary directory, real YAML parsing and real file writes.
It checks round-trip bytes/content, stale-state and confirmation refusal, scope/path/
size/corruption/duplicate/symlink refusal and compensation after a simulated second
filesystem commit failure. Actual CMS admin/nonce, attachment/upload and rendered UI
behavior require separate native integration checks; the old PHP compatibility
suite does not prove these boundaries.
