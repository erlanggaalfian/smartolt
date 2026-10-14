<?php
/**
 * Canon design-system checker for frontend/*.php
 * Flags: duplicate class attributes, class names with no CSS definition,
 * and reports remaining inline style= usage (dynamic ones are expected).
 *
 * Usage: php tools/check-canon.php
 * Exit 1 if any hard error (dup class / undefined class) is found.
 */

$root = dirname(__DIR__);
$cssFiles = [
    $root . '/frontend/public/style.css',
    $root . '/frontend/onu-detail-css.php',
];

$css = '';
foreach ($cssFiles as $f) {
    $css .= file_get_contents($f) . "\n";
}

$pages = glob($root . '/frontend/*.php');

// JS hook classes may be bound from any page (e.g. footer.php binds header markup),
// so collect every string/selector literal across all pages first.
$allSrc = '';
foreach ($pages as $p) {
    $allSrc .= file_get_contents($p);
}

$errors = [];
$inlineReport = [];

foreach ($pages as $page) {
    $name = basename($page);
    if ($name === 'onu-detail-css.php') {
        continue;
    }
    $src = file_get_contents($page);

    // Per-page CSS = global CSS + any local <style> blocks
    preg_match_all('/<style>(.*?)<\/style>/s', $src, $styleBlocks);
    $scope = $css . implode("\n", $styleBlocks[1]);
    preg_match_all('/\.([a-zA-Z_][\w-]*)/', $scope, $dm);
    $defined = array_flip($dm[1]);

    // 1. duplicate class attributes on one tag (browser ignores the 2nd)
    preg_match_all('/<[a-zA-Z][^<>]*?class="[^"]*"[^<>]*?class="[^"]*"[^<>]*>/', $src, $dups);
    foreach ($dups[0] as $tag) {
        $errors[] = sprintf('%s: duplicate class attribute: %s', $name, substr(trim($tag), 0, 120));
    }

    // 2. class names with no definition anywhere (skip JS-hook-only names)
    preg_match_all('/class="([^"]*)"/', $src, $cm);
    $seen = [];
    foreach ($cm[1] as $val) {
        // strip PHP and JS interpolation before splitting
        $val = preg_replace('/<\?.*?\?>/s', '', $val);
        $val = preg_replace("/'\s*\+.*?\+\s*'/s", '', $val);
        foreach (preg_split('/\s+/', trim($val)) as $cls) {
            if ($cls === '' || !preg_match('/^[a-zA-Z][\w-]*$/', $cls)) {
                continue;
            }
            if (isset($defined[$cls]) || isset($seen[$cls])) {
                continue;
            }
            $seen[$cls] = true;
            // JS hook: referenced as a selector or string literal in any page
            if (strpos($allSrc, "'" . $cls . "'") !== false || strpos($allSrc, '.' . $cls) !== false) {
                continue;
            }
            $errors[] = sprintf('%s: class "%s" has no CSS definition', $name, $cls);
        }
    }

    $inline = preg_match_all('/style="/', $src);
    if ($inline > 0) {
        $inlineReport[$name] = $inline;
    }
}

foreach ($errors as $e) {
    echo "ERROR  $e\n";
}

echo "\n-- remaining inline style= (dynamic values are expected) --\n";
ksort($inlineReport);
foreach ($inlineReport as $file => $count) {
    printf("  %-34s %d\n", $file, $count);
}

printf("\n%d error(s)\n", count($errors));
exit(count($errors) > 0 ? 1 : 0);
