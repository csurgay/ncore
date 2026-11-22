<?php
/**
 * nCore figyelő – közös függvények (DB, RSS, párosítás, e-mail).
 */
declare(strict_types=1);

date_default_timezone_set('Europe/Budapest');

function ncore_config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $file = __DIR__ . '/config.php';
        if (!is_file($file)) {
            throw new RuntimeException('Hiányzik a config.php – másold le a config.example.php-t és töltsd ki.');
        }
        $cfg = require $file;
    }
    return $cfg;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = ncore_config()['db'];
        $pdo = new PDO($c['dsn'], $c['user'] ?? null, $c['pass'] ?? null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

function h(mixed $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

/** Kisbetűs, ékezet nélküli, csak [a-z0-9] és szóköz. */
function normalize(string $s): string
{
    $s = mb_strtolower($s, 'UTF-8');
    $s = strtr($s, [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ö' => 'o', 'ő' => 'o',
        'ú' => 'u', 'ü' => 'u', 'ű' => 'u',
    ]);
    $s = preg_replace('/[^a-z0-9]+/', ' ', $s) ?? '';
    return trim($s);
}

/**
 * Egy release cím szétszedése.
 * Pl. "The.Show.S02E05.720p.WEB.H264.HUN-XYZ" → season 2, episode 5, 720p, HUN.
 */
function parse_release(string $title, string $category = ''): array
{
    $n = normalize($title);
    $p = ' ' . $n . ' ';

    $season = $episode = null;
    if (preg_match('/ s(\d{1,2}) ?e(\d{1,3})(?: |e)/', $p, $m)) {
        $season = (int)$m[1];
        $episode = (int)$m[2];
    } elseif (preg_match('/ (\d{1,2})x(\d{2,3}) /', $p, $m)) {
        $season = (int)$m[1];
        $episode = (int)$m[2];
    } elseif (preg_match('/ s(\d{1,2}) /', $p, $m)) {
        $season = (int)$m[1];          // évadpakk, epizódszám nélkül
    }

    $quality = 'SD';
    if (preg_match('/ (2160p|4k|uhd) /', $p)) {
        $quality = '2160p';
    } elseif (preg_match('/ 1080[pi] /', $p)) {
        $quality = '1080p';
    } elseif (str_contains($p, ' 720p ')) {
        $quality = '720p';
    }

    $cat = normalize($category);
    $isHun = str_contains($p, ' hun ') || str_contains(' ' . $cat . ' ', 'hun');
    $lang = $isHun ? 'HUN' : 'ENG';

    return [
        'norm'    => $n,
        'season'  => $season,
        'episode' => $episode,
        'quality' => $quality,
        'lang'    => $lang,
    ];
}

/**
 * Kulcsszavak: szóközzel elválasztva, mind kell (teljes szóként).
 * "-szo" kizár: ha szerepel, nem talált.
 */
function keywords_match(string $keywords, string $norm): bool
{
    $hay = ' ' . $norm . ' ';
    $any = false;
    foreach (preg_split('/\s+/', trim($keywords)) ?: [] as $kw) {
        if ($kw === '') {
            continue;
        }
        $neg = str_starts_with($kw, '-');
        $w = normalize($neg ? substr($kw, 1) : $kw);
        if ($w === '') {
            continue;
        }
        $found = str_contains($hay, ' ' . $w . ' ');
        if ($neg && $found) {
            return false;
        }
        if (!$neg) {
            if (!$found) {
                return false;
            }
            $any = true;
        }
    }
    return $any;
}

/** Illik-e a release a figyelt tételre (az aktuális évad/epizód állapot alapján). */
function item_matches(array $item, array $rel): bool
{
    $keywords = trim((string)$item['keywords']) !== '' ? (string)$item['keywords'] : (string)$item['name'];
    if (!keywords_match($keywords, $rel['norm'])) {
        return false;
    }
    if ($item['lang'] !== 'any' && $item['lang'] !== $rel['lang']) {
        return false;
    }
    if ($item['quality'] !== 'any' && $item['quality'] !== $rel['quality']) {
        return false;
    }

    if ($item['kind'] === 'film') {
        return $rel['season'] === null;      // sorozat-epizód ne jelezzen filmként
    }

    // sorozat
    if ($rel['season'] === null) {
        return false;
    }
    $s = $item['season'] !== null ? (int)$item['season'] : null;
    $e = $item['last_episode'] !== null ? (int)$item['last_episode'] : null;
    if ($s === null) {
        return true;
    }
    if ($rel['season'] < $s) {
        return false;
    }
    if ($rel['season'] > $s) {
        return true;
    }
    // ugyanaz az évad
    if ($rel['episode'] === null) {
        return $e === null;                  // évadpakk csak, ha még egy epizódot sem láttunk
    }
    return $e === null || $rel['episode'] > $e;
}

/** A link a torrent adatlapjára mutasson, ne a passkey-es letöltő linkre. */
function torrent_key_and_link(string $guid, string $link): array
{
    foreach ([$link, $guid] as $u) {
        if (preg_match('/[?&]id=(\d+)/', $u, $m)) {
            return ['id:' . $m[1], 'https://ncore.pro/torrents.php?action=details&id=' . $m[1]];
        }
    }
    return ['h:' . sha1($guid !== '' ? $guid : $link), null];
}

function parse_feed_xml(string $xml): array
{
    $prev = libxml_use_internal_errors(true);
    $doc = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NOCDATA | LIBXML_NONET);
    libxml_use_internal_errors($prev);
    if ($doc === false || !isset($doc->channel)) {
        throw new RuntimeException('Az RSS nem értelmezhető (lejárt passkey vagy hibás URL?).');
    }
    $out = [];
    foreach ($doc->channel->item as $it) {
        $title = trim((string)$it->title);
        if ($title === '') {
            continue;
        }
        [$key, $link] = torrent_key_and_link(trim((string)$it->guid), trim((string)$it->link));
        $out[$key] = [
            'key'      => $key,
            'title'    => $title,
            'link'     => $link,
            'category' => trim((string)$it->category),
        ];
    }
    return array_values($out);
}

function fetch_feed(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS | CURLPROTO_HTTP,
        CURLOPT_USERAGENT      => 'csurgay-ncore-figyelo/1.0',
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err  = curl_error($ch);
    if ($body === false || $code >= 400) {
        throw new RuntimeException('RSS letöltési hiba (HTTP ' . $code . ') ' . $err);
    }
    return parse_feed_xml((string)$body);
}

function fetch_all_feeds(): array
{
    $all = [];
    foreach (ncore_config()['feeds'] as $url) {
        foreach (fetch_feed($url) as $r) {
            $all[$r['key']] = $r;
        }
    }
    return array_values($all);
}

/** Már értesített (item, torrent) párok. */
function already_seen(int $itemId, string $key): bool
{
    $st = db()->prepare('SELECT 1 FROM seen_torrents WHERE item_id = ? AND guid = ?');
    $st->execute([$itemId, $key]);
    return (bool)$st->fetchColumn();
}

/**
 * Egy teljes ellenőrzés.
 * $send=false: csak előnézet – nem küld levelet, nem ír az adatbázisba.
 * $releases: teszteléshez kívülről adható; null esetén az RSS-ekből jön.
 */
function run_check(bool $send = true, ?array $releases = null): array
{
    $result = ['read' => 0, 'matches' => [], 'error' => null, 'mailed' => false];
    try {
        $releases ??= fetch_all_feeds();
        $result['read'] = count($releases);

        $items = db()->query('SELECT * FROM watch_items WHERE active = 1 ORDER BY name')->fetchAll();
        foreach ($releases as $r) {
            $rel = parse_release($r['title'], $r['category'] ?? '');
            foreach ($items as $item) {
                if (item_matches($item, $rel) && !already_seen((int)$item['id'], $r['key'])) {
                    $result['matches'][] = ['item' => $item, 'release' => $r, 'parsed' => $rel];
                }
            }
        }

        if ($send && $result['matches']) {
            send_mail($result['matches']);
            $result['mailed'] = true;
            record_matches($result['matches']);
        }
    } catch (Throwable $e) {
        $result['error'] = $e->getMessage();
    }

    if ($send) {
        log_run($result);
    }
    return $result;
}

function record_matches(array $matches): void
{
    $pdo = db();
    $pdo->beginTransaction();
    $ins = $pdo->prepare('INSERT INTO seen_torrents (item_id, guid, title, link, notified_at) VALUES (?, ?, ?, ?, ?)');
    $state = [];
    foreach ($matches as $m) {
        $id = (int)$m['item']['id'];
        $ins->execute([$id, $m['release']['key'], mb_substr($m['release']['title'], 0, 255), $m['release']['link'], now()]);
        $state[$id] ??= $m['item'];
        $p = $m['parsed'];
        if ($m['item']['kind'] === 'sorozat' && $p['season'] !== null) {
            $cs = $state[$id]['season'] !== null ? (int)$state[$id]['season'] : null;
            $ce = $state[$id]['last_episode'] !== null ? (int)$state[$id]['last_episode'] : null;
            if ($cs === null || $p['season'] > $cs) {
                $state[$id]['season'] = $p['season'];
                $state[$id]['last_episode'] = $p['episode'];
            } elseif ($p['season'] === $cs && $p['episode'] !== null && ($ce === null || $p['episode'] > $ce)) {
                $state[$id]['last_episode'] = $p['episode'];
            }
        }
    }
    $upd = $pdo->prepare('UPDATE watch_items SET season = ?, last_episode = ?, last_match_at = ?, active = ? WHERE id = ?');
    foreach ($state as $id => $it) {
        $active = (int)$it['stop_after_match'] === 1 ? 0 : 1;
        $upd->execute([$it['season'], $it['last_episode'], now(), $active, $id]);
    }
    $pdo->commit();
}

function log_run(array $result): void
{
    try {
        $pdo = db();
        $pdo->prepare('INSERT INTO run_log (run_at, items_read, matches, error) VALUES (?, ?, ?, ?)')
            ->execute([now(), $result['read'], count($result['matches']),
                $result['error'] !== null ? mb_substr($result['error'], 0, 500) : null]);
        $pdo->prepare('DELETE FROM run_log WHERE run_at < ?')
            ->execute([date('Y-m-d H:i:s', time() - 30 * 86400)]);
    } catch (Throwable) {
        // a naplózás hibája ne állítsa meg a futást
    }
}

function send_mail(array $matches): void
{
    $cfg = ncore_config()['mail'];
    $n = count($matches);
    $subject = 'nCore: ' . $n . ' új találat';

    $lines = [];
    foreach ($matches as $m) {
        $p = $m['parsed'];
        $ep = $p['season'] !== null
            ? sprintf(' – S%02d%s', $p['season'], $p['episode'] !== null ? sprintf('E%02d', $p['episode']) : ' (évadpakk)')
            : '';
        $lines[] = '• ' . $m['item']['name'] . $ep . ' [' . $p['lang'] . ', ' . $p['quality'] . ']';
        $lines[] = '  ' . $m['release']['title'];
        if ($m['release']['link']) {
            $lines[] = '  ' . $m['release']['link'];
        }
        $lines[] = '';
    }
    if (!empty($cfg['site_url'])) {
        $lines[] = 'Lista szerkesztése: ' . $cfg['site_url'];
    }
    $body = implode("\r\n", $lines);

    $headers = implode("\r\n", [
        'From: ' . mb_encode_mimeheader($cfg['from_name'] ?? 'nCore figyelő', 'UTF-8') . ' <' . $cfg['from'] . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ]);

    $ok = mail($cfg['to'], mb_encode_mimeheader($subject, 'UTF-8'), $body, $headers, '-f' . $cfg['from']);
    if (!$ok) {
        throw new RuntimeException('A levél küldése nem sikerült (mail()).');
    }
}
