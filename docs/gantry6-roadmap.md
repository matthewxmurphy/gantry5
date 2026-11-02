# Gantry 6 accepted build order

Owner direction: one shared core with native CMS adapters. The goal is fast
rendering, complete discoverability and consistent control for people and agents.
This is planned scope, not a claim that the listed features are implemented.

Developer: Matthew X Murphy, https://MatthewxMurphy.com.
Support: https://builtnotbegged.com. Existing project licenses apply.

1. **Shared changes first.** Preview/save/undo with revisions must be one engine
   for native admin, REST and the later MCP transport. No later feature builds
   on separate save paths. The current candidate is documented in
   [shared changes](shared-changes.md).
2. **Production rendering and assets.** Resolve inheritance, configuration and
   layout at save/publish into a versioned, warmed render bundle with atomic
   activation. Warm front-end requests perform zero YAML parsing and zero
   configuration-directory discovery. Particle manifests load only used assets;
   dependency-ordered deferred JS, precomputed critical CSS and responsive
   AVIF/WebP include fallbacks/dimensions, with the main visible image eager.
   Joomla Web Asset Manager remains authoritative.
3. **Extension registry and native MCP.** One row per extension records its
   endpoints, documentation, auth, permissions and version. States are
   documented, discovered without schema, or none declared. MCP exposes the
   same validated operations under the same CMS identity/permissions, with
   standards-compliant HTTP auth. Discovery does not invent missing schemas.
4. **Metadata completeness.** Audit final rendered metadata by URL/language,
   identify each supplying component and offer suggested wording/preview. Run
   on save or in the background, adding zero front-end work. Length is guidance,
   not a pass/fail score. Examples use JoomlaMafia: improve `Home | Home` to
   `Joomla Tutorials and Extensions | JoomlaMafia`, and a generic welcome
   description to wording that accurately describes JoomlaMafia's real content.
5. **Template Club last.** Native admin installation of themes/particles follows
   compatibility/authenticity checks and a tested rollback path.

## Gates

- **Benchmark before claiming faster.** Use identical real staging content;
  record cold and warm runs, median and slow-request timings, memory, asset
  bytes and actual mobile rendering. Publish measured scope and limits.
- **Port-forward regression gate.** Batch 1 defects verified in 6.0.0-alpha.1
  must not survive the accepted Gantry 6 release: [#2282 radio buttons](https://github.com/gantry/gantry5/issues/2282),
  [#2519 Owl Carousel sizing](https://github.com/gantry/gantry5/issues/2519),
  [#2486 Unicode outline names/slugs](https://github.com/gantry/gantry5/issues/2486),
  [#2894 Font Awesome 5 assets](https://github.com/gantry/gantry5/issues/2894)
  and [#3419 debug CSS](https://github.com/gantry/gantry5/issues/3419).
  [#2172 collection-particle columns](https://github.com/gantry/gantry5/issues/2172)
  is a Gantry 6 feature to build here. Each needs its relevant CMS/browser
  reproduction and acceptance; a source diff alone is not a port-forward pass.
- **Fresh-agent MCP gate.** A fresh agent must complete a real round-trip through
  the installed endpoint; documentation, schema inspection and a familiar
  developer's local call do not complete it.
- **Site boundary.** Gantry 6 verification uses the PHP 8.5 staging clone.
  Gantry 5/PHP 8.4 and `testing.joomlamafia.com` remain untouched by this work.
