# Simple Multi Language Site (SMLS)

A Grav plugin for multi-language sites that keep **separate content trees per language** (e.g. `user/pages/vi/`, `user/pages/en/`) instead of Grav's native same-folder-with-suffix i18n. Each page explicitly declares its own language and points to its counterpart pages in the other configured languages via frontmatter — no assumption that URLs mirror each other between languages.

## Features

- **Free-form language list**: define any number of languages with your own codes (not hardcoded to `vi`/`en`/`fr`), each with a `root_path` (the folder that holds that language's pages) and a designated default language.
- **Per-page language + translation links**: every page gets a language select field and one translation-link field per other configured language, added directly under the Content tab's editor. The translation picker only lists pages that share the same **template** as the page being edited.
- **Bidirectional sync**: linking page A → page B on save automatically links page B → page A too, so you only ever set the link from one side.
- **Add Translation**: for any language not yet linked, a button pre-fills Grav Admin's "Add Page" screen with the target language and a back-link to the current page already set.
- **Language Converter** (`Admin > Multi Language`): bulk-assigns `language` to legacy pages that don't have it yet, based on `root_path` prefix matching (100% deterministic, no guessing), plus a report of pages still missing a counterpart in some language — pairing itself is always left to a human, never auto-guessed.
- **Legacy-field fallback**: if a page already has an old singular `translation: /some/route` field (from a hand-rolled i18n setup that predates this plugin), it's still read as one valid link until the page is next saved through the new UI.
- **Owns the site-root redirect** (optional, on by default): `/` gets redirected to the default language's `root_path` — change which language is default in one place (plugin config) instead of also having to hand-edit a `site.yaml` redirect. Turn off via **Tự quản lý redirect trang gốc "/"** if you'd rather manage that redirect yourself.

## Requirements

- Grav >= 1.7.0, with the **Admin** plugin.
- `admin.super` to access `Admin > Multi Language` (Language Converter). Any user who can edit pages can use the per-page language/translation fields.

## Installation

Lives as a git submodule at `user/plugins/simple-multi-language-site/`, pointing to its own repo (not on GPM). To add it to another site: `git submodule add git@github.com:tipforeveryone/grav-plugin-simple-multi-language-site.git user/plugins/simple-multi-language-site`. Enable it under `Admin > Plugins > Simple Multi Language Site`.

## Configuration

Go to `Admin > Plugins > Simple Multi Language Site`:

| Field | Description |
|---|---|
| **Plugin status** | Enable/disable the whole plugin |
| **Languages list** | One row per language: **code** (free-form, ISO-style recommended e.g. `vi`/`en`/`fr`), **label** (display name), **root path** (the folder holding that language's pages, e.g. `/vi`) |
| **Default language** | Applied to legacy pages that don't have a `language` field yet, and as the default for new pages. Also drives the `/` root redirect (see below) unless that's turned off. |
| **Tự quản lý redirect trang gốc "/"** | On by default: overrides `site.redirects['^/$']` at runtime to point at the default language's `root_path`. Turn off if you want to manage that redirect yourself in `site.yaml`. |

The plugin needs **at least 2 languages configured** before it injects anything into page-edit forms or the frontend switcher.

## Usage

1. Edit any page — under the **Content** tab, below the main editor, you'll find a language select and one translation-link row per other configured language (each row only shows pages using the same template as the current page).
2. Pick the current page's language, then either select an existing translated page from the dropdown, or click **Add Translation** to scaffold a new one (pre-filled with the target language and a back-link).
3. Save — the counterpart page is automatically updated to link back.
4. `Admin > Multi Language` — run the bulk language-assignment for legacy pages, and check the "still missing a translation" report.

## Theme integration

The plugin exposes Twig functions any theme can call — nothing is auto-injected into templates, since layout/markup is the theme's responsibility. The functions relevant to a theme:

| Function | Returns | Use |
|---|---|---|
| `smls_languages()` | `[{code, label, root_path}, ...]` | Iterate configured languages (e.g. to build a switcher) |
| `smls_default_language()` | `string` | Fallback when a page has no language set |
| `smls_current_language(page)` | `string` | The page's language (falls back to path-prefix match against `root_path`, then to the default) |
| `smls_switch_route(page, targetCode)` | `string\|null` | The linked route for `targetCode`, or `null` if not linked yet |
| `smls_root_path(code)` | `string\|null` | The configured `root_path` for a language code |

### Where exactly to add these calls

For a theme that has never integrated this plugin before, there are **5 insertion points**. #1 and #2 are required for the plugin to do anything visible; #3 only applies if the theme organizes content in per-language folder trees; #4 is a convenience; #5 is a recommended safety net, not a functional requirement.

**1. Base/layout template — `<html lang>` attribute (required)**

```twig
<html lang="{{ smls_current_language(page) ?: smls_default_language() }}">
```

**2. Language switcher UI — wherever the theme shows one (header, footer, mobile nav...) (required for a working switcher)**

```twig
{% set current_lang = smls_current_language(page) %}
{% for lang in smls_languages() %}
    {% if lang.code != current_lang %}
        {% set switch_route = smls_switch_route(page, lang.code) %}
        {% if switch_route %}
            <a href="{{ base_url ~ switch_route }}">{{ lang.code|upper }}</a>
        {% endif %}
    {% endif %}
{% endfor %}
```

**3. Any lookup of "a page scoped to the current language" — only if the theme's content is organized per-language folder (e.g. finding a nav/config page under the current language's root)**

```twig
{% set current_lang = smls_current_language(page) %}
{% set my_scoped_page = page.find(smls_root_path(current_lang) ~ '/some-page') %}
```

**4. Any place branching on "is this page in language X" for conditional text/labels — optional convenience**

```twig
{% set is_en = smls_current_language(page) == 'en' %}
```

Replaces the old-style `uri.path starts with '/en'` check.

**5. The theme's own PHP class (`<theme-name>.php`) — recommended, not required to function**

Register fallback versions of the `smls_*` functions the theme actually calls, gated behind the plugin's `enabled` config flag, so disabling/removing the plugin later doesn't hard-break the theme. See the "Hard dependency once integrated" section below for why and how.

See [`eznotary/templates/partials/header.html.twig`](../../themes/eznotary/templates/partials/header.html.twig), [`base.html.twig`](../../themes/eznotary/templates/partials/base.html.twig) and [`article-list.html.twig`](../../themes/eznotary/templates/article-list.html.twig) for the real integration this plugin was built against (covers points 1–4).

## ⚠️ Hard dependency once integrated

Once a theme calls `smls_*` functions, **disabling or removing this plugin without also handling that in the theme will break every page** with a Twig "Unknown function" error — these calls are not optional/silently-ignorable like a missing partial would be.

Two ways to stay safe:

1. **Simplest**: treat this plugin as a required dependency of the theme, same as the Admin plugin itself — document it, don't disable it without also reverting the theme's `smls_*` calls.
2. **Safer**: have the theme register its own fallback versions of the functions it actually calls, gated behind the plugin's own `enabled` config flag — see [`eznotary/eznotary.php`](../../themes/eznotary/eznotary.php)'s `onTwigInitialized()` for a working reference implementation.

If you build a fallback like that, **do not** use `$twig->hasFunction()`/`getFunction()` to check whether the real function is already registered before deciding to add a fallback — calling either of those methods forces Twig to finalize its extension list immediately, and if the plugin then tries to register its real functions afterward, Twig throws `"Unable to register extension ... as extensions have already been initialized"`. Check `plugins.simple-multi-language-site.enabled` from config instead, and only register fallbacks when it's falsy.

Also skip fallback registration entirely when `\Grav\Common\Utils::isAdminPlugin()` is true — Admin uses its own theme and never calls these functions, and registering them unconditionally there was observed to occasionally lock the whole Admin panel out (same Twig-extension-timing class of issue as above, just triggered from the Admin side instead of the frontend).

## Data model

Stored directly in each page's frontmatter, no separate storage:

```yaml
smls_language: en
smls_translations:
    vi: /vi/some-page
```

- `smls_language` — this page's language code.
- `smls_translations` — map of `code => route` for other languages. Empty/missing means "no translation yet" (the frontend switcher simply won't show a link for that language).
- `translation` (singular, legacy) — pre-plugin ad hoc field some pages already had; still read as a fallback for one link, migrated naturally the next time the page is saved through the new UI.

### ⚠️ Why not just `language` / `translations`?

Those were the original field names, and they broke the Admin page list. **`header.language` is not a free key — Grav core reads it.** `Grav\Common\Page\Page` (`system/src/Grav/Common/Page/Page.php`) unconditionally copies `header.language` into the page object's internal `language()` property on init, *regardless of whether `system.languages.supported` is configured*. Admin's own page-list link builder (`AdminTwigExtension::getPageUrl()` → `Admin::getAdminRoute()`) then uses that value to prepend a `/<language>` segment **before** the admin route — so any page with `header.language` set got Admin edit links like `/vi/admin/pages/...` instead of `/admin/pages/...`, which don't resolve (Admin's router doesn't expect a language prefix ahead of its own route unless native multi-language is actually active) and land on a 404/error page instead.

This is Admin core behavior clearly built for Grav's native i18n, that fires unconditionally on the mere presence of the field — nothing this plugin can suppress. The fix is simply not colliding with the key: **never use a bare `language`/`translations` header field for this kind of thing** — always namespace it.

## Author

**tipforeveryone** — MIT License.
