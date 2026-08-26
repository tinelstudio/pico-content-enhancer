# TineL Content Enhancer

A [Pico](http://picocms.org) CMS plugin that post-processes a page after Pico has parsed it, fixing five things that are awkward to do in Markdown and impossible to do in a theme.

Each job is independent and can be switched off.

## Description

### 1. URL placeholders in meta headers

Pico expands `%base_url%`, `%assets_url%` and friends in a page's **body**, but never in its **meta header** - `parseFileMeta()` runs on the raw file and `substituteFileContent()` only touches what is left after the meta block has been stripped.

That is why this does not work in stock Pico:

```yaml
---
Assets: '%assets_url%/docs/'
---
```

Without it you are pushed towards relative paths like `../../assets/docs/`, which resolve differently depending on how deep the site is mounted - fine on a site that *is* the domain root, broken on a copy served from a subdirectory. This plugin expands the placeholders on `onMetaParsed`, so the meta value works the same in both.

> **Note:** quote the value. A bare `%` at the start of a YAML scalar is a reserved indicator and the parser rejects it - the page will fail with a 500 and an empty body.

### 2. Image attributes

Adds `loading="lazy"`, `decoding="async"` and intrinsic `width`/`height` to every image in the content. The dimensions are read from the file itself with `getimagesize()` and cached per request; they are what stop the page from jumping around as photos arrive.

Old browsers ignore `loading` and `decoding`, and `width`/`height` are HTML 3.2 attributes, so nothing regresses on old devices.

Pair it with this in your stylesheet, or images will be stretched:

```css
img {
  max-width: 100%;
  height: auto;   /* required companion to the width/height attributes */
}
```

### 3. Table wrappers

Wraps every Markdown table in a scrolling container, so your stylesheet does not have to resort to `table { display: block }` to get a horizontal scrollbar - which works, but throws away table layout, so column widths stop resolving from content.

```css
.tlTableScroll {
  overflow-x: auto;
}
```

### 4. Heading ids

Adds `id` attributes to headings, server side, so `page#Some-Heading` resolves without JavaScript. Off by default.

The slug rule is deliberately minimal: runs of dots and whitespace collapse to a single hyphen and nothing else changes - **no lowercasing, no stripping of punctuation**. That matches what a typical client-side anchor script produces, so turning this on does not invalidate links that already exist somewhere you cannot edit.

Two headings with the same text produce the same id. That is left as it is on purpose: a page with two identical headings is a content problem, and silently renaming one to `-2` hides it while making the anchor unpredictable to link to.

### 5. Root-relative links

Rewrites links written as `/page#anchor` so they resolve against the **site** root rather than the **server** root.

Those are the same thing only while the site *is* the domain root. A copy served from a subdirectory - a development mirror, or a site mounted under a path - sees `/page` point outside itself, and every internal link breaks.

The alternative is writing `%base_url%/page` throughout the content, which works but costs the readability of every link. Doing it here keeps the Markdown plain:

```markdown
[Car info](/product#Car-info)
```

Deliberately a no-op when the site is mounted at the domain root, so production output is unchanged.

### Bonus: canonical URL

Sets a `canonical_url` Twig variable. Pico builds `current_page.url` from whatever host the visitor typed, so `www` and non-`www` each declare themselves canonical - which tells search engines they are two separate originals rather than one page reachable two ways. Setting `canonical_base_url` pins every canonical to one host without redirecting anything, so both host names keep serving normally.

```twig
<link rel="canonical" href="{{ canonical_url|default(current_page.url) }}" />
```

## Configuration

Add the settings below to `config/config.yml`, or to a file of your own inside Pico's `config/` directory - for example `config/tinel-content-enhancer.yml`. Pico reads every `.yml` file in that directory automatically.

The key is the plugin's class name, which is how Pico's `getPluginConfig()` looks it up.

```yaml
TineLContentEnhancer:

    # Expand %base_url%, %assets_url% etc. inside meta header values.
    substitute_meta_urls: true

    # Add loading, decoding and intrinsic width/height to every <img>.
    enhance_images: true

    # Wrap tables in a scrolling container.
    wrap_tables: true
    table_wrapper_class: tlTableScroll

    # Add id attributes to headings, server side.
    heading_ids: false

    # Rewrite root-relative links (/page#anchor) to resolve against the site
    # root. No-op when the site is the domain root.
    rebase_root_links: true

    # Pin <link rel="canonical"> to one host. Leave empty to use the requested host.
    canonical_base_url: ''
```

Every setting shown is the default, so an empty configuration behaves as above.

## Installation

### With Composer (recommended)

```
composer require tinelstudio/pico-content-enhancer
```

Pico installs plugins through [`picocms/composer-installer`][installer], which places this package in Pico's `plugins/` directory rather than in `vendor/`. If your site does not have it yet, add it once:

```
composer require picocms/composer-installer
```

Composer 2 also needs the installer allowed in your root `composer.json`:

```json
"config": {
    "allow-plugins": {
        "picocms/composer-installer": true
    }
}
```

### By hand

Copy `TineLContentEnhancer.php` into Pico's `plugins/` directory, in a folder named `TineLContentEnhancer`. Pico loads plugins from there directly, no autoloader involved.

[installer]: https://github.com/picocms/composer-installer

## License

MIT - see [LICENSE](LICENSE).
