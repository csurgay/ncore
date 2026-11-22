<?php
/**
 * nCore figyelő – a figyelt tételek szerkesztése (csurgay.com/ncore).
 */
declare(strict_types=1);

require __DIR__ . '/lib.php';

session_name('ncore_sess');
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();

const KINDS     = ['sorozat' => 'Sorozat', 'film' => 'Film'];
const LANGS     = ['HUN' => 'HUN', 'ENG' => 'ENG (nem HUN)', 'any' => 'mindegy'];
const QUALITIES = ['any' => 'mindegy', 'SD' => 'SD', '720p' => '720p', '1080p' => '1080p', '2160p' => '2160p'];

$cfg = ncore_config();
$self = strtok($_SERVER['REQUEST_URI'] ?? 'index.php', '?') ?: 'index.php';

function redirect(string $to): never
{
    header('Location: ' . $to, true, 303);
    exit;
}

function flash(?string $msg = null): ?string
{
    if ($msg !== null) {
        $_SESSION['flash'] = $msg;
        return null;
    }
    $m = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $m;
}

function int_or_null(mixed $v): ?int
{
    $v = trim((string)$v);
    return $v === '' ? null : max(0, (int)$v);
}

$_SESSION['csrf'] ??= bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];
$authed = !empty($_SESSION['ok']);
$action = $_POST['action'] ?? '';

// --- Bejelentkezés ---------------------------------------------------------
if ($action === 'login') {
    $hash = (string)($cfg['ui_password_hash'] ?? '');
    if ($hash !== '' && password_verify((string)($_POST['password'] ?? ''), $hash)) {
        session_regenerate_id(true);
        $_SESSION['ok'] = true;
        redirect($self);
    }
    sleep(2);
    flash('Hibás jelszó.');
    redirect($self);
}

if ($authed && $action !== '') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Érvénytelen kérés.');
    }
    $pdo = db();
    $id = (int)($_POST['id'] ?? 0);

    switch ($action) {
        case 'logout':
            session_destroy();
            redirect($self);

        case 'save':
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') {
                flash('A név kötelező.');
                redirect($self . ($id ? '?edit=' . $id : ''));
            }
            $kind = array_key_exists($_POST['kind'] ?? '', KINDS) ? $_POST['kind'] : 'sorozat';
            $data = [
                mb_substr($name, 0, 200),
                mb_substr(trim((string)($_POST['keywords'] ?? '')), 0, 255),
                $kind,
                array_key_exists($_POST['lang'] ?? '', LANGS) ? $_POST['lang'] : 'HUN',
                array_key_exists($_POST['quality'] ?? '', QUALITIES) ? $_POST['quality'] : 'any',
                $kind === 'sorozat' ? int_or_null($_POST['season'] ?? '') : null,
                $kind === 'sorozat' ? int_or_null($_POST['last_episode'] ?? '') : null,
                isset($_POST['stop_after_match']) ? 1 : 0,
                isset($_POST['active']) ? 1 : 0,
                mb_substr(trim((string)($_POST['note'] ?? '')), 0, 255) ?: null,
            ];
            if ($id) {
                $pdo->prepare('UPDATE watch_items SET name=?, keywords=?, kind=?, lang=?, quality=?, season=?,
                    last_episode=?, stop_after_match=?, active=?, note=? WHERE id=?')
                    ->execute([...$data, $id]);
                flash('Mentve: ' . $name);
            } else {
                $pdo->prepare('INSERT INTO watch_items (name, keywords, kind, lang, quality, season, last_episode,
                    stop_after_match, active, note, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute([...$data, now()]);
                flash('Felvéve: ' . $name);
            }
            redirect($self);

        case 'toggle':
            $pdo->prepare('UPDATE watch_items SET active = 1 - active WHERE id = ?')->execute([$id]);
            redirect($self);

        case 'delete':
            $pdo->prepare('DELETE FROM seen_torrents WHERE item_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM watch_items WHERE id = ?')->execute([$id]);
            flash('Törölve.');
            redirect($self);

        case 'preview':
            $_SESSION['preview'] = run_check(false);
            redirect($self . '#preview');
    }
}

// --- Megjelenítés ------------------------------------------------------------
$msg = flash();
$items = $runs = $recent = [];
$edit = null;
$preview = null;
if ($authed) {
    $pdo = db();
    $items = $pdo->query('SELECT * FROM watch_items ORDER BY active DESC, name')->fetchAll();
    $recent = $pdo->query('SELECT s.*, w.name FROM seen_torrents s JOIN watch_items w ON w.id = s.item_id
        ORDER BY s.notified_at DESC LIMIT 20')->fetchAll();
    $runs = $pdo->query('SELECT * FROM run_log ORDER BY run_at DESC LIMIT 10')->fetchAll();
    if (isset($_GET['edit'])) {
        $st = $pdo->prepare('SELECT * FROM watch_items WHERE id = ?');
        $st->execute([(int)$_GET['edit']]);
        $edit = $st->fetch() ?: null;
    }
    $preview = $_SESSION['preview'] ?? null;
    unset($_SESSION['preview']);
}
$e = $edit ?? ['id' => 0, 'name' => '', 'keywords' => '', 'kind' => 'sorozat', 'lang' => 'HUN', 'quality' => 'any',
    'season' => null, 'last_episode' => null, 'stop_after_match' => 0, 'active' => 1, 'note' => ''];

function options(array $opts, string $sel): string
{
    $out = '';
    foreach ($opts as $k => $label) {
        $out .= '<option value="' . h($k) . '"' . ($k === $sel ? ' selected' : '') . '>' . h($label) . '</option>';
    }
    return $out;
}

function episode_label(array $i): string
{
    if ($i['kind'] !== 'sorozat') {
        return '–';
    }
    if ($i['season'] === null) {
        return 'bármelyik';
    }
    return sprintf('S%02d', $i['season']) . ($i['last_episode'] !== null ? sprintf('E%02d', $i['last_episode']) : '');
}
?>
<!DOCTYPE html>
<html lang="hu">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>nCore figyelő</title>
<style>
    :root { --fg:#1d2330; --muted:#6b7280; --line:#e5e7eb; --accent:#2563eb; --bg:#f8fafc; }
    * { box-sizing: border-box; }
    body { font: 15px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; color: var(--fg); background: var(--bg); margin: 0; }
    main { max-width: 1050px; margin: 0 auto; padding: 24px 16px 64px; }
    h1 { font-size: 22px; margin: 0 0 4px; }
    h2 { font-size: 17px; margin: 32px 0 10px; }
    .muted { color: var(--muted); font-size: 13px; }
    .card { background: #fff; border: 1px solid var(--line); border-radius: 10px; padding: 16px; }
    table { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid var(--line); border-radius: 10px; overflow: hidden; }
    th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid var(--line); vertical-align: top; }
    th { font-size: 12px; text-transform: uppercase; letter-spacing: .04em; color: var(--muted); background: #f9fafb; }
    tr.off td { color: #9ca3af; }
    form.inline { display: inline; }
    button, .btn { font: inherit; font-size: 13px; padding: 5px 10px; border: 1px solid var(--line); border-radius: 6px; background: #fff; cursor: pointer; color: var(--fg); text-decoration: none; }
    button.primary { background: var(--accent); border-color: var(--accent); color: #fff; }
    button.danger { color: #b91c1c; }
    .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 12px; }
    label { display: block; font-size: 13px; color: var(--muted); }
    input[type=text], input[type=number], input[type=password], select { width: 100%; font: inherit; padding: 6px 8px; border: 1px solid #d1d5db; border-radius: 6px; margin-top: 2px; color: var(--fg); }
    .checks label { display: inline-flex; gap: 6px; align-items: center; margin-right: 16px; color: var(--fg); }
    .flash { background: #ecfdf5; border: 1px solid #a7f3d0; padding: 8px 12px; border-radius: 8px; margin: 12px 0; }
    .err { background: #fef2f2; border-color: #fecaca; }
    header { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; }
    code { font-size: 12px; }
</style>
</head>
<body>
<main>
<?php if (!$authed): ?>
    <h1>nCore figyelő</h1>
    <?php if ($msg): ?><div class="flash err"><?= h($msg) ?></div><?php endif; ?>
    <form method="post" class="card" style="max-width:320px;margin-top:16px">
        <input type="hidden" name="action" value="login">
        <label>Jelszó <input type="password" name="password" autofocus required></label>
        <p><button class="primary">Belépés</button></p>
        <?php if (empty($cfg['ui_password_hash'])): ?>
            <p class="muted">A config.php-ban még nincs beállítva <code>ui_password_hash</code>.</p>
        <?php endif; ?>
    </form>
<?php else: ?>
    <header>
        <div>
            <h1>nCore figyelő</h1>
            <div class="muted">Óránként ellenőrzi az RSS-t, és e-mailt küld az új találatokról.</div>
        </div>
        <div>
            <form method="post" class="inline"><input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <button name="action" value="preview" title="Most lekéri az RSS-t, de nem küld levelet">Előnézet most</button>
                <button name="action" value="logout">Kilépés</button>
            </form>
        </div>
    </header>
    <?php if ($msg): ?><div class="flash"><?= h($msg) ?></div><?php endif; ?>

    <h2><?= $edit ? 'Szerkesztés: ' . h($edit['name']) : 'Új tétel' ?></h2>
    <form method="post" class="card">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
        <div class="grid">
            <label>Név <input type="text" name="name" value="<?= h($e['name']) ?>" required></label>
            <label>Kulcsszavak <input type="text" name="keywords" value="<?= h($e['keywords']) ?>" placeholder="üresen: a név szavai"></label>
            <label>Típus <select name="kind"><?= options(KINDS, $e['kind']) ?></select></label>
            <label>Nyelv <select name="lang"><?= options(LANGS, $e['lang']) ?></select></label>
            <label>Minőség <select name="quality"><?= options(QUALITIES, $e['quality']) ?></select></label>
            <label>Évad <input type="number" name="season" min="0" value="<?= h($e['season']) ?>" placeholder="bármelyik"></label>
            <label>Utolsó látott epizód <input type="number" name="last_episode" min="0" value="<?= h($e['last_episode']) ?>" placeholder="még egy sem"></label>
            <label>Megjegyzés <input type="text" name="note" value="<?= h($e['note']) ?>"></label>
        </div>
        <p class="checks">
            <label><input type="checkbox" name="active" <?= (int)$e['active'] ? 'checked' : '' ?>> aktív</label>
            <label><input type="checkbox" name="stop_after_match" <?= (int)$e['stop_after_match'] ? 'checked' : '' ?>> első találat után kikapcsol (filmhez)</label>
        </p>
        <button class="primary"><?= $edit ? 'Mentés' : 'Felvétel' ?></button>
        <?php if ($edit): ?><a class="btn" href="<?= h($self) ?>">Mégse</a><?php endif; ?>
        <p class="muted">Kulcsszavak: mind szerepeljen a címben, teljes szóként, kis/nagybetű és ékezet nem számít.
            <code>-szo</code> kizár (pl. <code>the office -us</code>). Sorozatnál az évadnál vagy az utolsó epizódnál
            újabbra jelez, és a számot magától lépteti.</p>
    </form>

    <h2>Figyelt tételek (<?= count($items) ?>)</h2>
    <table>
        <tr><th>Név</th><th>Kulcsszavak</th><th>Nyelv</th><th>Minőség</th><th>Állás</th><th>Utolsó találat</th><th></th></tr>
        <?php foreach ($items as $i): ?>
            <tr class="<?= (int)$i['active'] ? '' : 'off' ?>">
                <td><strong><?= h($i['name']) ?></strong><br><span class="muted"><?= h(KINDS[$i['kind']]) ?><?= $i['note'] ? ' · ' . h($i['note']) : '' ?></span></td>
                <td><?= h($i['keywords'] !== '' ? $i['keywords'] : $i['name']) ?></td>
                <td><?= h($i['lang']) ?></td>
                <td><?= h(QUALITIES[$i['quality']]) ?></td>
                <td><?= h(episode_label($i)) ?></td>
                <td class="muted"><?= h($i['last_match_at'] ?? '–') ?></td>
                <td style="white-space:nowrap">
                    <a class="btn" href="?edit=<?= (int)$i['id'] ?>">Szerk.</a>
                    <form method="post" class="inline">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="id" value="<?= (int)$i['id'] ?>">
                        <button name="action" value="toggle"><?= (int)$i['active'] ? 'Kikapcs.' : 'Bekapcs.' ?></button>
                        <button name="action" value="delete" class="danger" onclick="return confirm('Biztosan törlöd?')">Töröl</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$items): ?><tr><td colspan="7" class="muted">Még nincs figyelt tétel.</td></tr><?php endif; ?>
    </table>

    <?php if ($preview !== null): ?>
        <h2 id="preview">Előnézet (levél nem ment ki)</h2>
        <div class="card">
            <?php if ($preview['error']): ?>
                <div class="flash err"><?= h($preview['error']) ?></div>
            <?php else: ?>
                <p class="muted">RSS elemek: <?= (int)$preview['read'] ?> · új találat: <?= count($preview['matches']) ?></p>
                <?php foreach ($preview['matches'] as $m): ?>
                    <div><strong><?= h($m['item']['name']) ?></strong> ← <?= h($m['release']['title']) ?>
                        <?php if ($m['release']['link']): ?> · <a href="<?= h($m['release']['link']) ?>" target="_blank" rel="noopener">adatlap</a><?php endif; ?></div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <h2>Utolsó értesítések</h2>
    <table>
        <tr><th>Mikor</th><th>Tétel</th><th>Release</th></tr>
        <?php foreach ($recent as $r): ?>
            <tr>
                <td class="muted" style="white-space:nowrap"><?= h($r['notified_at']) ?></td>
                <td><?= h($r['name']) ?></td>
                <td><?php if ($r['link']): ?><a href="<?= h($r['link']) ?>" target="_blank" rel="noopener"><?= h($r['title']) ?></a><?php else: ?><?= h($r['title']) ?><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$recent): ?><tr><td colspan="3" class="muted">Még nem volt értesítés.</td></tr><?php endif; ?>
    </table>

    <h2>Utolsó futások</h2>
    <table>
        <tr><th>Mikor</th><th>RSS elemek</th><th>Találat</th><th>Hiba</th></tr>
        <?php foreach ($runs as $r): ?>
            <tr>
                <td class="muted" style="white-space:nowrap"><?= h($r['run_at']) ?></td>
                <td><?= (int)$r['items_read'] ?></td>
                <td><?= (int)$r['matches'] ?></td>
                <td><?= h($r['error'] ?? '') ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$runs): ?><tr><td colspan="4" class="muted">A cron még nem futott – ellenőrizd a cPanel Cron Jobs beállítást.</td></tr><?php endif; ?>
    </table>
<?php endif; ?>
</main>
</body>
</html>
