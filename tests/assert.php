<?php

/** Compares with var_export so arrays/null/strings all print readably. */
function check(string $label, $actual, $expected): void
{
    $a = var_export($actual, true);
    $e = var_export($expected, true);
    if ($a === $e) {
        echo "PASS: {$label}\n";
        return;
    }
    echo "FAIL: {$label}\n  expected: {$e}\n  actual:   {$a}\n";
    $GLOBALS['__fail'] = true;
}

function done(): void
{
    if (!empty($GLOBALS['__fail'])) {
        echo "\nSOME TESTS FAILED\n";
        exit(1);
    }
    echo "\nALL TESTS PASSED\n";
    exit(0);
}
