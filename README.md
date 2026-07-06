# maho-module-generator

Generate best-practice [Maho](https://mahocommerce.com) modules from a
declarative YAML spec - and lint existing modules against the same rules.

Think UMC (Ultimate Module Creator) for Magento 1, rebuilt for modern
Maho: spec-first, headless, round-trippable.

```bash
# scaffold a full module (models, schema, controllers, admin grid+form,
# layout, templates, emails, locale CSV, CI workflow) from ~40 lines of YAML
maho-module-gen generate specs/example-testimonials.yaml --out my-module/

# delta mode: add spec artifacts to an EXISTING module - never overwrites
maho-module-gen generate my-spec.yaml --into my-module/

# lint ANY module - generated or hand-written - against 15+ rules that each
# correspond to a real production bug class
maho-module-gen lint app/code/community/Vendor/Name

# golden-diff a spec-generated module: missing / identical / drifted per file
maho-module-gen audit my-spec.yaml my-module/

# clean-room a Magento 1 module: derive a SPEC (structure only, never code)
# for human review, then generate a fresh Maho module from it
maho-module-gen extract path/to/m1-module --out extracted.yaml
```

## Web UI (local)

```bash
php -S localhost:8080 -t web
```

Spec editor -> module zip, and M1-zip upload -> clean-room spec.
**Local tool only** - no auth; see [web/README.md](web/README.md).

## CI for your modules

One line in any module repo's workflow gets it linted on every push/PR
(fails on critical findings only):

```yaml
jobs:
  lint:
    uses: mageaustralia/maho-module-generator/.github/workflows/lint.yml@main
```

Every generated file embodies the current best practice: `#[Maho\Config\Route]`
attributes on every action, declarative `sql/schema.php` with `addUniqueIndex`,
CSRF-forced admin actions, `strict_types`, `Maho\Data\Form`, responsive email
skeletons, sorted locale CSVs. The linter catches the same classes of mistake
in code written by hand.

See [DESIGN.md](DESIGN.md) for the architecture (pure-library core → CLI /
web service / Magento-1 clean-room converter all wrap the same engine),
the full lint rule table, and the roadmap.

## Install

```bash
composer require --dev mageaustralia/maho-module-generator
vendor/bin/maho-module-gen list
```

## Spec format

See [specs/example-testimonials.yaml](specs/example-testimonials.yaml) for
the annotated reference. Minimal viable spec:

```yaml
module: {vendor: Acme, name: Widgets}
entities:
  widget:
    columns:
      name: {type: string, notnull: true}
```

Unknown keys are fatal by design - typos fail at parse time, not silently
at generate time.

## License

OSL-3.0.
