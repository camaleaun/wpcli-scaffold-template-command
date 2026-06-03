# wpcli-scaffold-template-command

Generic WP-CLI scaffold engine driven by **YAML + Mustache** template packs.

```bash
wp scaffold template <vendor/repo> <slug> [--param=value ...]
```

## How it works

A **template pack** is any GitHub repository (or local directory) containing:

```
my-template-pack/
├── scaffold.yml       ← declares parameters, computed values, variable mapping and files
└── templates/
    ├── thing.mustache
    └── ...
```

The engine:
1. Clones (or updates) the pack from GitHub into `~/.wp-cli/scaffold-templates/`
2. Reads `scaffold.yml` with the bundled YAML parser (`Mustangostang\Spyc`)
3. Resolves all variables (defaults → derive → CLI flags → computed)
4. Renders each `.mustache` file with the bundled Mustache engine (`Mustache_Engine`)
5. Writes files to the output directory
6. Runs post-actions (activate plugin/theme, etc.)

No extra Composer dependencies — both parsers are bundled inside `wp-cli.phar`.

## Installation

```bash
wp package install camaleaun/wpcli-scaffold-template-command
```

## Usage

```bash
# From GitHub (latest main)
wp scaffold template camaleaun/wp-scaffold-plugin my-plugin

# Pin to tag or branch
wp scaffold template camaleaun/wp-scaffold-plugin@1.2.0 my-plugin

# Pass template parameters
wp scaffold template camaleaun/wp-scaffold-plugin my-plugin \
  --plugin_name="My Plugin" \
  --plugin_author="Jane Doe" \
  --plugin_author_uri="https://example.com" \
  --activate

# Use a local template pack
wp scaffold template ./path/to/my-template my-thing

# Overwrite existing files
wp scaffold template camaleaun/wp-scaffold-plugin my-plugin --force
```

## scaffold.yml reference

```yaml
name: vendor/repo
description: Human-readable description
version: 1
engine: ">=1.0.0"   # minimum engine version required

# ── Parameters ────────────────────────────────────────────────────────────────
# Exposed as CLI flags. `default: ~` means derived at runtime via `derive`.
parameters:
  plugin_name:
    description: "What to put in the 'Plugin Name:' header"
    default: ~
    derive: "ucwords(slug, '-')"   # expression evaluated when default is null
  plugin_author:
    default: "YOUR NAME HERE"
  skip-tests:
    type: flag        # boolean flag, not a string value
    default: false
  ci:
    default: github
    options: [github, gitlab, circle, bitbucket]

# ── Computed ──────────────────────────────────────────────────────────────────
# Derived automatically — not exposed as CLI flags.
# Available functions: str_replace, strtoupper, strtolower, ucwords, implode, explode, strpos
# Concatenation operator: ~
# Special variables always available: slug, wp_version
computed:
  plugin_namespace: "str_replace(' ', '\\\\', plugin_name)"
  plugin_const_prefix: "strtoupper(str_replace('-', '_', slug))"
  textdomain: slug

# ── Variables ─────────────────────────────────────────────────────────────────
# Explicit mapping: template placeholder → parameter or computed name.
# Placeholders not listed here resolve by matching their own name directly.
variables:
  plugin_name: plugin_name       # {{plugin_name}} → value of plugin_name
  slug: slug                     # {{slug}} → positional <slug> argument

# ── Files ─────────────────────────────────────────────────────────────────────
files:
  core:                          # always generated
    items:
      - dest: "{{slug}}.php"     # dest is also Mustache-rendered
        template: plugin.mustache

  tests:
    skip_when: { flag: skip-tests }   # entire group skipped when --skip-tests
    items:
      - dest: phpunit.xml
        template: plugin-phpunit.mustache

  ci:
    select_by: { param: ci }     # only emit the item matching --ci value
    items:
      - dest: .github/workflows/tests.yml
        template: plugin-ci-github.mustache
        when: github
      - dest: .gitlab-ci.yml
        template: plugin-ci-gitlab.mustache
        when: gitlab

# ── Post-actions ──────────────────────────────────────────────────────────────
post_actions:
  - action: plugin_activate
    when: { flag: activate }
  - action: theme_activate
    when: { flag: activate }
```

## Template packs

| Pack | Command |
|---|---|
| [camaleaun/wp-scaffold-plugin](https://github.com/camaleaun/wp-scaffold-plugin) | `wp scaffold template camaleaun/wp-scaffold-plugin` |

## Testing

Tested with Behat (`wp-cli/wp-cli-tests`):

```bash
composer prepare-tests
composer behat
```
