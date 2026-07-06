# Maho Module Generator - design

Generate best-practice Maho modules from a declarative YAML spec, and
lint existing modules against the same rules. Think UMC (Ultimate Module
Creator) for Magento 1, rebuilt for modern Maho - spec-first, headless,
and round-trippable.

## Why

Hand-written modules drift. Even careful authors (human or AI) reproduce
the same classes of mistake: `Zend_Mail` for outbound email, missing
`#[Maho\Config\Route]` attributes, hyphenated action segments in
`getUrl()`, `addUniqueConstraint` vs `addUniqueIndex` DDL drift, file
names that don't match class names on case-sensitive filesystems,
`helper()` methods colliding with `Mage_Core_Block_Abstract::helper($name)`.

Every one of those is a *pattern* - which means a generator whose
templates ARE the correct pattern can't produce them, and a linter that
knows the patterns can catch them in code that was written by hand.

## Architecture

```
                    ┌──────────────────────────┐
   spec.yaml ──────▶│  Spec (parse + validate)  │
                    └────────────┬─────────────┘
                                 ▼
                    ┌──────────────────────────┐
                    │  Generator (pure library) │──▶ array<relative-path, contents>
                    └────────────┬─────────────┘
              ┌──────────────────┼──────────────────┐
              ▼                  ▼                  ▼
        CLI `generate`     web service         M1 converter
        (writes files)     (returns zip)       (M1 module → spec → regenerate)
```

The core rule: **`Generator::generate(Spec $spec): array` is pure.**
No filesystem writes, no Maho boot, no DB. Everything side-effectful
lives in the wrappers. That is what makes the same engine servable over
HTTP later, and what makes the M1 clean-room path tractable - the
converter's only job is to emit a *spec*, never code.

### Components (v0.1)

| Component | Role |
|---|---|
| `Spec` | Parses YAML, normalises defaults, validates (unknown keys fatal, so typos surface immediately) |
| `Generator` | Orchestrates artifact generators, returns the file map |
| `Artifact\*` | One class per generated file type; each template embodies the current best practice |
| `Linter` | Pattern checks against an existing module directory (no spec needed) |
| `bin/maho-module-gen` | Symfony Console CLI: `generate`, `lint` |

### Artifacts generated per spec (v0.1)

- `composer.json` (type `maho-module`, `maho-module-dir` extra)
- `app/etc/modules/{Vendor}_{Name}.xml`
- `etc/config.xml` - helpers / models (+ resourceModel + entities) /
  blocks / frontend router + layout + translate / admin router /
  registered email templates
- `etc/adminhtml.xml` - menu node + ACL resources
- `sql/schema.php` - declarative Doctrine DBAL; `addUniqueIndex` (never
  `addUniqueConstraint` - see DDL-drift note below), `PrimaryKeyConstraint`
  editor pattern, FKs with explicit names
- `Helper/Data.php`
- `Model/{Entity}.php` - `_eventPrefix`, timestamp stamping via
  `Mage_Core_Model_Locale::nowUtc()`, `@method` docblocks from columns
- `Model/Resource/{Entity}.php` + `Collection.php`
- Frontend controller - `#[Maho\Config\Route]` attribute on every action,
  `_validateFormKey()` on every POST action
- Admin controller - `ADMIN_RESOURCE` const, `_isAllowed()`,
  `_setForcedFormKeyActions()`, `#[Route]` attributes, grid + edit actions
- Admin grid container + grid block (columns derived from entity schema)
- Admin layout XML (one handle per action)
- Frontend layout XML + list template (`escapeHtml` / `escapeUrl` only)
- Responsive email template skeleton per declared email (registered in
  `config.xml`, `<!--@subject@-->` directive, no `{{foreach}}`)
- `app/locale/en_US/{Vendor}_{Name}.csv` - auto-collected from every
  translatable string the generator itself emitted, sorted `strnatcasecmp`
- `README.md`, `.gitignore`, `.github/workflows/ci.yml`

### Lint rules (v0.1)

Each rule = id, severity, grep-or-AST check, one-line fix hint:

| id | severity | catches |
|---|---|---|
| `zend-classes` | critical | `Zend_*` usage (incl. `Zend_Mail`, `Zend_Date`, `Zend_Db` - allowlists DBAL aliases) |
| `varien-classes` | critical | `Varien_*` where a `Maho\*` replacement exists |
| `legacy-date` | warning | `Mage_Core_Model_Date`, `Locale::now()`, raw `date()` feeding DB writes |
| `route-attributes` | critical | controller action without `#[Maho\Config\Route]` |
| `hyphen-action-url` | critical | `getUrl('...a-b...')` hyphen/underscore in the action segment |
| `escape-htmlattr` | critical | `escapeHtmlAttr(` (method does not exist - silent output loss) |
| `admin-resource` | critical | admin controller missing `ADMIN_RESOURCE` or `_isAllowed()` |
| `csrf-forced` | critical | state-changing admin action set without `_setForcedFormKeyActions` |
| `strict-types` | warning | PHP file missing `declare(strict_types=1)` |
| `unique-constraint-ddl` | warning | `addUniqueConstraint(` in `sql/schema.php` (DDL-drift vs legacy tables) |
| `case-mismatch` | critical | file basename ≠ class name tail (Linux-vs-macOS footgun) |
| `block-helper-collision` | critical | Block subclass declaring `helper()` with incompatible signature |
| `email-foreach` | warning | `{{foreach}}` in email templates (unsupported by Maho's filter) |
| `raw-json` | nit | `json_encode`/`json_decode` where `Mage::helper('core')` variants preferred |

Lint runs against ANY module directory - generated or hand-written -
so it doubles as CI: `maho-module-gen lint app/code/community/Vendor/Name`.

### The DDL-drift note

Doctrine DBAL's diff engine treats `UniqueConstraint` objects and
unique **indexes** as distinct metadata. MySQL stores a legacy
`CREATE TABLE ... UNIQUE KEY` as a unique index (`Non_unique=0`), so a
`schema.php` declaring `addUniqueConstraint` on the same columns never
compares equal - the differ plans `DROP INDEX`, which MySQL refuses when
a FK depends on it, and the whole migration aborts. `addUniqueIndex`
produces metadata that round-trips. The generator only ever emits
`addUniqueIndex`; the linter flags `addUniqueConstraint`.

## Roadmap

- **v0.1** - `generate` + `lint` (this document)
- **v0.2** - `generate --into <existing-module>` delta mode: add an
  entity/controller/email to an existing module without clobbering
  hand-edited files (write only files that don't exist; print skips)
- **v0.3** - golden-diff audit: regenerate from spec, `diff --no-index`
  against the module, report structural drift
- **v0.4** - CI integration: reusable GitHub workflow step that runs
  `lint` on every PR
- **v1.0 - web service.** Thin HTTP wrapper over the same library.
  Two inputs:
  1. *Spec mode* - user writes (or an LLM writes from a plain-English
     prompt) the YAML; service returns a zip.
  2. *M1 clean-room mode* - user uploads a Magento 1 module; an
     extractor derives a spec from its config.xml + setup scripts +
     controllers (names, tables, columns, routes, ACL - **structure
     only, never code**), the human reviews/edits the spec, and the
     generator emits a fresh Maho module. Because only the spec crosses
     the boundary, the output is a clean-room reimplementation, not a
     port - no source expression from the M1 module survives into the
     output.

## Spec format

See `specs/example-testimonials.yaml` for the full annotated reference.
Design rules for the format itself:

- Unknown keys are **fatal**, not ignored - a typo like `uniqe:` should
  fail loudly at parse time, not silently generate a module without the
  constraint.
- Everything has a sensible default: a minimal viable spec is ~10 lines
  (vendor, name, one entity with two columns).
- The spec is the *interface contract* for all future modes. The M1
  extractor emits it; the web UI edits it; the generator consumes it.
