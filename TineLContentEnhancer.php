<?php

/**
 * TineL Content Enhancer (Pico Plugin) - Post-process parsed content and meta.
 *
 * Four jobs, all of which have to happen after Pico has done its own work:
 *
 *  1. Expand %base_url%, %assets_url% etc. inside META HEADER values.
 *     Pico deliberately does not do this - parseFileMeta() runs on the raw file and substituteFileContent() only ever touches the body after the meta block has been stripped. So `Assets: %assets_url%/subpage/` is dead text without this. Without it you are forced into relative paths like `../../assets/subpage/`, which resolve differently depending on how deep the site is mounted - they work on a site at the domain root and break on one served from a sub directory.
 *
 *  2. Add loading="lazy", decoding="async" and intrinsic width/height to every <img>. The dimensions are what stop the page jumping around as photos arrive. Old browsers ignore `loading`; width and height are HTML 3.2 attributes, so nothing regresses on ancient devices.
 *
 *  3. Wrap every <table> in a scrolling <div>, so the stylesheet no longer has to set `display: block` on tables - which gets the scrollbar at the cost of throwing away table layout entirely.
 *
 *  4. Optionally add id attributes to headings, server side (off by default). See the note on slug compatibility in slugify() before enabling.
 *
 * @author  TineL Studio
 * @license http://opensource.org/licenses/MIT The MIT License
 * @version 1.0.1
 */
class TineLContentEnhancer extends AbstractPicoPlugin {
    /**
     * API version used by this plugin
     *
     * @var int
     */
    const API_VERSION = 4;

    /**
     * Cached getimagesize() results, keyed by image URL
     *
     * Pages repeat the same image, and a render is a single request, so a plain per-request array is enough.
     *
     * @var array
     */
    protected $sizeCache = [];

    /**
     * Triggered after Pico has parsed the meta header of the requested page
     *
     * @param string[] &$meta parsed meta data
     */
    public function onMetaParsed(array &$meta) {
        if (!$this->getPluginConfig('substitute_meta_urls', true)) return;

        $pico = $this->getPico();

        // Expands Pico's URL placeholders inside meta values. Only the six placeholders Pico::substituteUrl() knows about are touched, so a stray percent sign in a description is left exactly as written.
        foreach ($meta as $key => $value) {
            if (is_string($value) && strpos($value, '%') !== false) {
                $meta[$key] = $pico->substituteUrl($value);
            }
        }
    }

    /**
     * Triggered after Pico has parsed the contents of the requested page
     *
     * @param string &$content parsed contents (HTML) of the requested page
     */
    public function onContentParsed(&$content) {
        if ($this->getPluginConfig('enhance_images', true)) {
            $content = $this->enhanceImages($content);
        }

        if ($this->getPluginConfig('wrap_tables', true)) {
            $content = $this->wrapTables($content);
        }

        if ($this->getPluginConfig('heading_ids', false)) {
            $content = $this->addHeadingIds($content);
        }

        if ($this->getPluginConfig('rebase_root_links', true)) {
            $content = $this->rebaseRootLinks($content);
        }
    }

    /**
     * Triggered before Pico renders the page
     *
     * @param string &$templateName  file name of the template
     * @param array  &$twigVariables template variables
     */
    public function onPageRendering(&$templateName, array &$twigVariables) {
        $url = isset($twigVariables['current_page']['url']) ? $twigVariables['current_page']['url'] : null;

        $canonicalBase = $this->getPluginConfig('canonical_base_url');

        // Adds a `canonical_url` Twig variable. Pico's `current_page.url` is built from whatever host the visitor typed, so www and non-www each declare themselves canonical - which tells search engines the two are separate originals rather than one page reachable two ways. Setting `canonical_base_url` pins every canonical to one host without redirecting anything, so both host names keep serving normally.
        if ($url && $canonicalBase) {
            $baseUrl = $this->getPico()->getBaseUrl();
            if (strpos($url, $baseUrl) === 0) {
                $url = rtrim($canonicalBase, '/') . '/' . substr($url, strlen($baseUrl));
            }
        }

        $twigVariables['canonical_url'] = $url;
    }

    /**
     * Adds lazy loading, async decoding and intrinsic dimensions to images
     *
     * Assumes Parsedown-generated markup, which is machine written and therefore predictable: <img src="..." alt="..." />. Images that already carry a `loading` attribute are left alone, so hand written HTML in a page always wins.
     *
     * @param  string $html parsed page contents
     * @return string
     */
    protected function enhanceImages($html) {
        return preg_replace_callback(
            '#<img\s+([^>]*?)\s*/?>#i',
            function ($match) {
                $attributes = $match[1];

                // Respect anything written by hand
                if (preg_match('#\bloading\s*=#i', $attributes)) return $match[0];

                $extra = ' loading="lazy" decoding="async"';

                $hasSize = preg_match('#\b(width|height)\s*=#i', $attributes);
                if (!$hasSize && preg_match('#\bsrc\s*=\s*"([^"]+)"#i', $attributes, $src)) {
                    $size = $this->imageSize(html_entity_decode($src[1], ENT_QUOTES, 'UTF-8'));
                    if ($size) {
                        $extra .= ' width="' . $size[0] . '" height="' . $size[1] . '"';
                    }
                }

                return '<img ' . rtrim($attributes) . $extra . ' />';
            },
            $html
        );
    }

    /**
     * Resolves an image URL to a local file and returns [width, height]
     *
     * Uses Pico's own assets_url/assets_dir pair rather than guessing at the file system layout. Returns false when the image lives somewhere this cannot see, in which case the caller simply omits the dimensions.
     *
     * @param  string $src image URL as it appears in the markup
     * @return array|false
     */
    protected function imageSize($src) {
        if (array_key_exists($src, $this->sizeCache)) return $this->sizeCache[$src];

        $pico = $this->getPico();
        $file = null;

        $assetsUrl = rtrim($pico->getConfig('assets_url'), '/') . '/';
        $assetsDir = rtrim($pico->getConfig('assets_dir'), '/') . '/';

        if (strpos($src, $assetsUrl) === 0) {
            $file = $assetsDir . substr($src, strlen($assetsUrl));
            $rootDir = $assetsDir;
        } else {
            $baseUrl = $pico->getBaseUrl();
            if (strpos($src, $baseUrl) === 0) {
                $file = $pico->getRootDir() . substr($src, strlen($baseUrl));
                $rootDir = $pico->getRootDir();
            }
        }

        $size = false;

        if ($file !== null) {
            // Keep '..' in a page from reaching outside the tree
            $real = realpath($file);
            $realRoot = realpath($rootDir);

            if ($real && $realRoot && strpos($real, $realRoot) === 0 && is_file($real)) {
                $info = @getimagesize($real);
                if ($info && !empty($info[0]) && !empty($info[1])) {
                    $size = [ (int) $info[0], (int) $info[1] ];
                }
            }
        }

        return $this->sizeCache[$src] = $size;
    }

    /**
     * Wraps tables in a horizontally scrolling container
     *
     * Matches Parsedown's bare <table>, which is what Markdown tables produce. A hand written <table class="..."> in a page is intentionally left alone.
     *
     * @param  string $html parsed page contents
     * @return string
     */
    protected function wrapTables($html) {
        if (strpos($html, '<table>') === false) return $html;

        $class = $this->getPluginConfig('table_wrapper_class', 'tlTableScroll');

        $html = str_replace('<table>', '<div class="' . $class . '"><table>', $html);
        $html = str_replace('</table>', '</table></div>', $html);

        return $html;
    }

    /**
     * Adds id attributes to headings
     *
     * Duplicate slugs get -2, -3 and so on, so two headings with the same text no longer both point at the first one.
     *
     * @param  string $html parsed page contents
     * @return string
     */
    protected function addHeadingIds($html) {
        $seen = []; // Reset per page

        return preg_replace_callback(
            '#<h([1-6])>(.*?)</h\1>#is',
            function ($match) use (&$seen) {
                $text = html_entity_decode(strip_tags($match[2]), ENT_QUOTES, 'UTF-8');
                $slug = $this->slugify($text);

                if ($slug === '') return $match[0];

                $slug = $this->uniqueSlug($slug, $seen);

                return '<h' . $match[1] . ' id="' . htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') . '">' . $match[2] . '</h' . $match[1] . '>';
            },
            $html
        );
    }

    /**
     * Turns heading text into an anchor slug
     *
     * IMPORTANT - this deliberately reproduces the theme's existing JavaScript rule (collapse runs of dots and whitespace into a single hyphen, change nothing else, do NOT lowercase).
     *
     * @param  string $text heading text, tags already stripped
     * @return string
     */
    protected function slugify($text) {
        $slug = preg_replace('/[.\s]+/u', '-', trim($text));
        return trim($slug, '-');
    }

    /**
     * Returns a slug not yet used on this page, suffixing -2, -3 and so on
     *
     * Registers what it hands back as well as what it was asked for, so a heading whose own text slugifies to something like "Foo-2" cannot collide with a suffix generated for an earlier "Foo".
     *
     * @param  string   $slug  candidate slug
     * @param  string[] &$seen slugs already used on this page
     * @return string
     */
    protected function uniqueSlug($slug, array &$seen) {
        $candidate = $slug;
        $n = 1;

        while (isset($seen[$candidate])) {
            $n++;
            $candidate = $slug . '-' . $n;
        }

        $seen[$candidate] = true;

        return $candidate;
    }

    /**
     * Rewrites root-relative links so they survive a sub directory mount
     *
     * Content is written with plain links like [text](/page#anchor), which is by far the nicest thing to author - but "/page" means the SERVER root, not the SITE root. Those are the same thing only while the site is the domain root, so every internal link breaks on a development copy served from a sub directory.
     *
     * The alternative is writing %base_url%/page throughout the content, which works but costs the readability of every link. Doing it here means the Markdown stays plain.
     *
     * Deliberately a no-op when the site IS mounted at the domain root, so production HTML stays byte for byte identical.
     *
     * @param  string $html parsed page contents
     * @return string
     */
    protected function rebaseRootLinks($html) {
        $basePath = parse_url($this->getPico()->getBaseUrl(), PHP_URL_PATH);

        if (!$basePath || $basePath === '/') return $html;

        $basePath = '/' . trim($basePath, '/') . '/';

        // "/x" but never "//host/x" - protocol relative URLs are left alone
        return preg_replace('#\b(href|src)="/(?!/)#', '$1="' . $basePath, $html);
    }
}
