<?php
// Inline an SVG from within _incl (nginx-blocked) directly into the page, so
// brand assets are never served as standalone URLs. Reads the file and echoes
// the <svg>...</svg> (stripping any XML prolog/doctype/comments before it); it is
// NOT include()d, so an <?xml declaration can't be mis-parsed as PHP.
//
//   svg_inline('littlemiracles_branding/logo_color/Logo Inline.svg', 'lm-logo', 'Little Miracles');
//
// $wrapClass wraps the SVG in a <span> (for CSS sizing); $label sets an
// accessible name (else the graphic is marked decorative).

function svg_inline(string $relPath, string $wrapClass = '', string $label = ''): void {
    $base = realpath(__DIR__);
    $real = realpath(__DIR__ . '/' . ltrim($relPath, '/'));
    // Refuse anything outside _incl or not an .svg.
    if ($real === false || $base === false || !str_starts_with($real, $base . DIRECTORY_SEPARATOR)
        || strtolower(substr($real, -4)) !== '.svg') {
        return;
    }
    $svg = file_get_contents($real);
    if ($svg === false) return;

    // Drop anything before the opening <svg> tag (XML decl, doctype, comments).
    $svg = preg_replace('/^.*?(<svg\b)/is', '$1', $svg, 1);
    $svg = svg_scope($svg, $real);

    if ($wrapClass === '') { echo $svg; return; }

    $h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
    $a11y = $label !== '' ? ' role="img" aria-label="' . $h($label) . '"' : ' aria-hidden="true"';
    echo '<span class="' . $h($wrapClass) . '"' . $a11y . '>' . $svg . '</span>';
}

/**
 * Make one inlined SVG's ids and class names unique to itself.
 *
 * These files come out of Illustrator carrying `id="Layer_2"` and a <style>
 * block of `.cls-1 { fill: ... }`. Inlined, that <style> is a GLOBAL stylesheet:
 * `.cls-1` matches anything on the page, and inlining a second logo repeats both
 * the ids (invalid HTML) and the selectors, so whichever came last silently
 * repaints the other. The sidebar now shows the wordmark or the icon depending
 * on whether it is collapsed, so both are on the page at once and this stopped
 * being hypothetical.
 *
 * The prefix is derived from the file path, so it is stable between requests --
 * a random one would defeat HTTP caching of the surrounding page for no gain.
 */
function svg_scope(string $svg, string $realPath): string {
    $p = 's' . substr(md5($realPath), 0, 8) . '-';

    // Selectors inside the SVG's own <style> blocks.
    $svg = preg_replace_callback('/<style\b[^>]*>(.*?)<\/style>/is',
        static fn($m) => str_replace($m[1], preg_replace('/\.(cls-[\w-]+)/', '.' . $p . '$1', $m[1]), $m[0]),
        $svg);

    // class="cls-1 cls-2" -> class="PREFIXcls-1 PREFIXcls-2"
    $svg = preg_replace_callback('/\bclass="([^"]*)"/i', static function ($m) use ($p) {
        $parts = preg_split('/\s+/', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return 'class="' . implode(' ', array_map(static fn($c) => $p . $c, $parts)) . '"';
    }, $svg);

    // id="X" and every way an SVG refers back to one.
    $svg = preg_replace('/\bid="([^"]*)"/i',      'id="' . $p . '$1"', $svg);
    $svg = preg_replace('/url\(#([^)]*)\)/i',     'url(#' . $p . '$1)', $svg);
    $svg = preg_replace('/\b(xlink:href|href)="#([^"]*)"/i', '$1="#' . $p . '$2"', $svg);

    return $svg;
}

/* ---------------------------------------------------------------------------
 * ROOM ICONS
 *
 * Every centre names its rooms from the same five families, so a room name
 * gets its family's mark in front of it. The names are NOT consistent enough
 * to key on: the same room is "Amazing Asteroids" at Kanwal, "Amazing
 * Asteroids Pink" / "Amazing Asteroid Purple" (singular) at Tuggerah, and
 * "Amazing Asterouds" - Kidsoft's own typo - at Warnervale. So the match is
 * on a STEM of the distinctive word, against the name folded to letters only.
 * "Cool Comets" (Tuggerah, one room) has no mark and gets none; a room with no
 * icon simply renders as it always did.
 *
 * The map is declared ONCE here and published to the browser on the sprite's
 * data-map; window.roomIcon() in app.js does the folding. Every room name on
 * every screen arrives through a page script - there is not one server-
 * rendered room name in the app - so a PHP twin of the matcher would be dead
 * code today and a second copy of the rule to drift tomorrow. Read the map
 * from here if that ever changes; do not re-type the stems.
 *
 * The marks are drawn as ONE sprite of <symbol>s inlined into every page
 * (layout_footer), referenced with <use>. Repeating five paths per row of a
 * 40-room grid is what a sprite exists to avoid, and the assets still never
 * leave _incl - see the branding rules in CLAUDE.md.
 */
const ROOM_ICON_MAP = [
    'aster'   => 'amazing_asteroids',   // also catches Warnervale's "Asterouds"
    'angel'   => 'little_angels',
    'meteor'  => 'mighty_meteors',
    'rainbow' => 'radiant_rainbows',
    'star'    => 'shining_stars',
];

/** The one hidden sprite. Called by layout_footer(); nothing else needs it. */
function room_icon_sprite(): void {
    $dir  = __DIR__ . '/littlemiracles_branding/LM_rooms/';
    $syms = '';
    foreach (array_unique(array_values(ROOM_ICON_MAP)) as $key) {
        $svg = @file_get_contents($dir . $key . '.svg');
        if ($svg === false) continue;
        // Keep the file's own viewBox; drop its width/height so CSS sizes it.
        if (!preg_match('/<svg\b([^>]*)>(.*)<\/svg>/is', $svg, $m)) continue;
        preg_match('/viewBox="([^"]*)"/i', $m[1], $vb);
        $syms .= '<symbol id="rmi-' . $key . '" viewBox="' . htmlspecialchars($vb[1] ?? '0 0 24 24', ENT_QUOTES)
              . '">' . $m[2] . '</symbol>';
    }
    if ($syms === '') return;
    echo '<svg class="rm-sprite" aria-hidden="true" data-map="'
       . htmlspecialchars(json_encode(ROOM_ICON_MAP), ENT_QUOTES) . '">' . $syms . '</svg>';
}
