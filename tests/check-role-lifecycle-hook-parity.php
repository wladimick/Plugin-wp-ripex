<?php
/**
 * Static guard for Phase 05.
 *
 * The lifecycle bridge intentionally bypasses Ripex_Portal::__construct().
 * This test fails if hook registrations in the constructor diverge from
 * Ripex_Portal_Roles_Lifecycle::register_portal_hooks().
 */

$root = dirname(__DIR__);
$mainFile = $root . '/includes/class-ripex-portal.php';
$lifecycleFile = $root . '/includes/class-ripex-portal-roles-lifecycle.php';

function method_body($file, $methodName) {
    $source = file_get_contents($file);
    if ($source === false) {
        throw new RuntimeException('Cannot read ' . $file);
    }

    $tokens = token_get_all($source);
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) continue;

        $name = null;
        for ($j = $i + 1; $j < $count; $j++) {
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                $name = $tokens[$j][1];
                break;
            }
            if ($tokens[$j] === '{') break;
        }
        if ($name !== $methodName) continue;

        for (; $j < $count && $tokens[$j] !== '{'; $j++) {}
        if ($j >= $count) break;

        $depth = 0;
        $body = '';
        for (; $j < $count; $j++) {
            $token = $tokens[$j];
            $text = is_array($token) ? $token[1] : $token;
            if ($text === '{') {
                $depth++;
                if ($depth === 1) continue;
            } elseif ($text === '}') {
                $depth--;
                if ($depth === 0) return $body;
            }
            if ($depth >= 1) $body .= $text;
        }
    }

    throw new RuntimeException('Method not found: ' . $methodName);
}

function hook_statements($body) {
    preg_match_all('/\b(?:add_action|add_filter|add_shortcode)\s*\(.*?\);/s', $body, $matches);
    $out = [];

    foreach ($matches[0] as $statement) {
        $statement = preg_replace('/\s+/', ' ', trim($statement));
        $statement = str_replace('$portal', '$this', $statement);
        $statement = str_replace('Ripex_Portal::', 'self::', $statement);
        $out[] = $statement;
    }

    sort($out, SORT_STRING);
    return $out;
}

$constructorHooks = hook_statements(method_body($mainFile, '__construct'));
$lifecycleHooks = hook_statements(method_body($lifecycleFile, 'register_portal_hooks'));

if ($constructorHooks !== $lifecycleHooks) {
    fwrite(STDERR, "Phase 05 hook parity check failed.\n");

    $missing = array_values(array_diff($constructorHooks, $lifecycleHooks));
    $extra = array_values(array_diff($lifecycleHooks, $constructorHooks));

    if ($missing) {
        fwrite(STDERR, "Missing from lifecycle:\n - " . implode("\n - ", $missing) . "\n");
    }
    if ($extra) {
        fwrite(STDERR, "Extra in lifecycle:\n - " . implode("\n - ", $extra) . "\n");
    }
    exit(1);
}

fwrite(STDOUT, 'Phase 05 hook parity: OK (' . count($constructorHooks) . " registrations)\n");
