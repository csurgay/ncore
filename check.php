<?php
/**
 * Óránkénti ellenőrzés – cronból futtatandó:
 *   php /home/<user>/public_html/ncore/check.php
 * Előnézet (nem küld levelet, nem ír DB-be):
 *   php check.php --dry-run
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require __DIR__ . '/lib.php';

$dry = in_array('--dry-run', $argv, true);
$r = run_check(!$dry);

echo now(), ' | olvasott: ', $r['read'], ' | találat: ', count($r['matches']),
    $dry ? ' | (előnézet)' : ($r['mailed'] ? ' | levél elküldve' : ''), PHP_EOL;
foreach ($r['matches'] as $m) {
    echo '  ', $m['item']['name'], ' <- ', $m['release']['title'], PHP_EOL;
}
if ($r['error'] !== null) {
    fwrite(STDERR, 'HIBA: ' . $r['error'] . PHP_EOL);
    exit(1);
}
