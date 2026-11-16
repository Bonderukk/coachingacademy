<?php
/**
 * Order administration for coachingacademy.sk: the book orders stored by form.php.
 *
 * One file, no JavaScript. Pages are never cached, every change is a POST with a CSRF token,
 * sign-in attempts are limited per IP address, and customer data is decrypted only for display.
 */

define('CA_APP', true);
require dirname(__DIR__) . '/lib/app.php';

const SESSION_IDLE_SECONDS = 60 * 60;
const SESSION_MAX_SECONDS = 12 * 60 * 60;
const LOGIN_ATTEMPTS = 5;
const LOGIN_WINDOW_SECONDS = 15 * 60;
const PAGE_SIZE = 50;

// ---- Transport and headers ---------------------------------------------------------------------

if (!ca_is_https()) {
    header('Location: https://' . ca_host() . ($_SERVER['REQUEST_URI'] ?? '/admin/'), true, 302);
    exit;
}
header("Content-Security-Policy: default-src 'none'; style-src 'self'; img-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
header('Strict-Transport-Security: max-age=31536000');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

// ---- Page shell --------------------------------------------------------------------------------

/** Prints a whole page and stops. `$body` is trusted HTML built by this file. */
function page(string $title, string $body, ?string $user = null, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    $nav = '';
    if ($user !== null) {
        $nav = '<nav class="nav"><a href="./">Objednávky</a><a href="?a=log">Záznam aktivít</a>'
            . '<form method="post" action="?a=logout">' . csrf_field() . '<button class="link">Odhlásiť (' . ca_e($user) . ')</button></form></nav>';
    }
    echo '<!doctype html><html lang="sk"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex, nofollow"><title>' . ca_e($title) . ' – Coaching Academy</title>'
        . '<link rel="stylesheet" href="admin.css"></head><body>'
        . '<header class="top"><a class="brand" href="./">Coaching Academy <span>objednávky</span></a>' . $nav . '</header>'
        . '<main>' . $body . '</main></body></html>';
    exit;
}

function redirect(string $to): void
{
    header('Location: ' . $to, true, 303);
    exit;
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . ca_e($_SESSION['csrf'] ?? '') . '">';
}

function status_badge(string $status): string
{
    return '<span class="badge st-' . ca_e($status) . '">' . ca_e(CA_STATUSES[$status] ?? $status) . '</span>';
}

// ---- Set-up check, database, session -----------------------------------------------------------

$config = ca_config();
$pdo = null;
try {
    $pdo = ca_db();
} catch (Throwable $e) {
    error_log('admin: database: ' . $e->getMessage());
}
if (!$pdo || ca_key() === null || !is_array($config['admins']) || !$config['admins']) {
    // Deliberately vague: details belong in the server log, not on a public page.
    page('Administrácia', '<section class="card narrow"><h1>Administrácia nie je dostupná</h1>'
        . '<p>Administrácia ešte nie je nastavená alebo sa nepodarilo pripojiť k databáze.</p></section>', null, 503);
}

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.gc_maxlifetime', (string) SESSION_MAX_SECONDS);
session_name('__Host-ca_admin');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
session_start();

$now = time();
if (isset($_SESSION['user']) && ($now - ($_SESSION['seen_at'] ?? 0) > SESSION_IDLE_SECONDS || $now - ($_SESSION['login_at'] ?? 0) > SESSION_MAX_SECONDS)) {
    $_SESSION = [];
    session_regenerate_id(true);
}
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$user = isset($_SESSION['user']) && isset($config['admins'][$_SESSION['user']]) ? (string) $_SESSION['user'] : null;
if ($user !== null) {
    $_SESSION['seen_at'] = $now;
}

$action = is_string($_GET['a'] ?? null) ? $_GET['a'] : 'list';
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
if ($isPost && !hash_equals((string) $_SESSION['csrf'], is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : '')) {
    page('Chyba', '<section class="card narrow"><h1>Formulár vypršal</h1><p>Vráťte sa späť, obnovte stránku a skúste to znova.</p>'
        . '<p><a class="button" href="./">Späť na objednávky</a></p></section>', $user, 400);
}

try {
    ca_retention($pdo);
} catch (Throwable $e) {
    error_log('admin: retention: ' . $e->getMessage());
}

// ---- Sign in -----------------------------------------------------------------------------------

if ($user === null) {
    $error = '';
    $status = 200;
    // After signing in, go back to the order the e-mail link pointed to.
    $wanted = filter_var($_GET['id'] ?? ($_POST['id'] ?? ''), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($isPost && $action === 'login') {
        if (ca_throttle_count($pdo, 'login', LOGIN_WINDOW_SECONDS) >= LOGIN_ATTEMPTS) {
            $error = 'Príliš veľa neúspešných pokusov. Skúste to znova o 15 minút.';
            $status = 429;
        } else {
            $name = is_string($_POST['user'] ?? null) ? trim($_POST['user']) : '';
            $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
            $hash = is_string($config['admins'][$name] ?? null) ? $config['admins'][$name] : null;
            // An unknown name is checked against another account's hash, so both cases take equally long.
            $valid = password_verify($password, $hash ?? (string) reset($config['admins'])) && $hash !== null;
            if ($valid) {
                session_regenerate_id(true);
                $_SESSION['user'] = $name;
                $_SESSION['login_at'] = $_SESSION['seen_at'] = $now;
                $_SESSION['csrf'] = bin2hex(random_bytes(32));
                ca_event($pdo, $name, 'admin.login');
                redirect($wanted ? '?a=order&id=' . $wanted : './');
            }
            ca_throttle_hit($pdo, 'login');
            ca_event($pdo, 'neznámy', 'admin.login_failed');
            usleep(400000);
            $error = 'Nesprávne meno alebo heslo.';
            $status = 401;
        }
    }
    page('Prihlásenie', '<section class="card narrow"><h1>Prihlásenie</h1>'
        . ($error !== '' ? '<p class="alert" role="alert">' . ca_e($error) . '</p>' : '')
        . '<form method="post" action="?a=login" class="stack">' . csrf_field()
        . ($wanted ? '<input type="hidden" name="id" value="' . $wanted . '">' : '')
        . '<label>Meno<input name="user" autocomplete="username" required autofocus></label>'
        . '<label>Heslo<input name="password" type="password" autocomplete="current-password" required></label>'
        . '<button class="button">Prihlásiť sa</button></form></section>', null, $status);
}

// ---- Orders: loading and filtering -------------------------------------------------------------

/** Adds the order number and the decrypted customer to a database row. */
function hydrate(array $row): array
{
    $row['id'] = (int) $row['id'];
    $row['number'] = ca_order_number($row['id'], $row['created_at']);
    $row['customer'] = ca_decrypt($row['customer']) ?? [];
    foreach (['unit_cents', 'shipping_cents', 'total_cents'] as $key) {
        $row[$key] = $row[$key] === null ? null : (int) $row[$key];
    }
    // Orders stored before delivery options existed were sent by post (or as a PDF).
    $row['delivery'] = (string) ($row['delivery'] ?? '') !== '' ? $row['delivery'] : ($row['digital'] ? 'PDF e-mailom' : 'Poštou na Slovensku');
    return $row;
}

/** Newest orders first, optionally limited to one status and to those matching the search text. */
function find_orders(PDO $pdo, string $status, string $search): array
{
    if (isset(CA_STATUSES[$status])) {
        $query = $pdo->prepare('SELECT * FROM ca_orders WHERE status = ? ORDER BY id DESC LIMIT 5000');
        $query->execute([$status]);
    } else {
        $query = $pdo->query('SELECT * FROM ca_orders ORDER BY id DESC LIMIT 5000');
    }
    $orders = array_map('hydrate', $query->fetchAll());
    if ($search === '') {
        return $orders;
    }
    // Customer data is encrypted in the database, so the search runs here on the decrypted rows.
    $needle = mb_strtolower($search);
    return array_values(array_filter($orders, function (array $order) use ($needle) {
        $customer = $order['customer'];
        $haystack = mb_strtolower(implode(' ', [
            $order['number'], $order['item'], $customer['name'] ?? '', $customer['email'] ?? '', $customer['city'] ?? '', $customer['phone'] ?? '',
        ]));
        return mb_strpos($haystack, $needle) !== false;
    }));
}

function load_order(PDO $pdo, $id): ?array
{
    $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) {
        return null;
    }
    $query = $pdo->prepare('SELECT * FROM ca_orders WHERE id = ?');
    $query->execute([$id]);
    $row = $query->fetch();
    return $row ? hydrate($row) : null;
}

function not_found(string $user): void
{
    page('Nenájdené', '<section class="card narrow"><h1>Objednávka sa nenašla</h1><p><a class="button" href="./">Späť na objednávky</a></p></section>', $user, 404);
}

/** One CSV line for Excel: semicolons, quoted fields, and no cell that a spreadsheet would run as a formula. */
function csv_line(array $fields): string
{
    return implode(';', array_map(function ($field) {
        $field = (string) $field;
        if ($field !== '' && strpos("=+-@\t\r", $field[0]) !== false) {
            $field = "'" . $field;
        }
        return '"' . str_replace('"', '""', $field) . '"';
    }, $fields)) . "\r\n";
}

$filterStatus = is_string($_GET['s'] ?? null) && isset(CA_STATUSES[$_GET['s']]) ? $_GET['s'] : '';
$search = ca_one_line(is_string($_GET['q'] ?? null) ? $_GET['q'] : '', 100);
$filterQuery = http_build_query(array_filter(['s' => $filterStatus, 'q' => $search], 'strlen'));

// ---- Actions that change something (POST only) -------------------------------------------------

if ($action === 'logout' && $isPost) {
    ca_event($pdo, $user, 'admin.logout');
    $_SESSION = [];
    session_regenerate_id(true);
    redirect('./');
}

if (in_array($action, ['status', 'anonymize', 'delete'], true)) {
    if (!$isPost) {
        redirect('./');
    }
    $order = load_order($pdo, $_POST['id'] ?? '');
    if ($order === null) {
        not_found($user);
    }
    if ($action === 'status') {
        $to = is_string($_POST['to'] ?? null) ? $_POST['to'] : '';
        if (isset(CA_STATUSES[$to]) && $to !== $order['status']) {
            $pdo->prepare('UPDATE ca_orders SET status = ?, status_at = ? WHERE id = ?')->execute([$to, ca_now(), $order['id']]);
            ca_event($pdo, $user, 'order.status', $order['id'], CA_STATUSES[$order['status']] . ' → ' . CA_STATUSES[$to]);
        }
        redirect('?a=order&id=' . $order['id']);
    }
    if ($action === 'anonymize') {
        ca_order_anonymize($pdo, $order['id'], $user);
        redirect('?a=order&id=' . $order['id']);
    }
    // delete
    $pdo->prepare('DELETE FROM ca_events WHERE order_id = ?')->execute([$order['id']]);
    $pdo->prepare('DELETE FROM ca_orders WHERE id = ?')->execute([$order['id']]);
    ca_event($pdo, $user, 'order.deleted', null, 'č. ' . $order['number']);
    redirect('./');
}

// ---- Export ------------------------------------------------------------------------------------

if ($action === 'export') {
    $orders = find_orders($pdo, $filterStatus, $search);
    ca_event($pdo, $user, 'admin.export', null, 'počet objednávok: ' . count($orders));
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="objednavky-' . date('Y-m-d') . '.csv"');
    echo "\xEF\xBB\xBF" . csv_line(['Číslo', 'Dátum', 'Stav', 'Kniha', 'Ks', 'Doručenie', 'Suma (€)', 'Meno', 'E-mail', 'Telefón', 'Ulica', 'PSČ', 'Mesto', 'Poznámka']);
    foreach ($orders as $order) {
        $customer = $order['customer'];
        echo csv_line([
            $order['number'], ca_local($order['created_at']), CA_STATUSES[$order['status']] ?? $order['status'], $order['item'], $order['quantity'],
            $order['delivery'], $order['total_cents'] === null ? '' : number_format($order['total_cents'] / 100, 2, ',', ''),
            $customer['name'] ?? '', $customer['email'] ?? '', $customer['phone'] ?? '', $customer['street'] ?? '', $customer['zip'] ?? '',
            $customer['city'] ?? '', $customer['note'] ?? '',
        ]);
    }
    exit;
}

// ---- Activity log ------------------------------------------------------------------------------

/** An activity-log line in plain Slovak. */
function event_text(array $event): string
{
    $texts = [
        'order.created' => 'Objednávka prijatá z webu',
        'order.status' => 'Zmena stavu',
        'order.anonymized' => 'Osobné údaje vymazané',
        'order.deleted' => 'Objednávka vymazaná',
        'order.mail_failed' => 'E-mail s upozornením sa nepodarilo odoslať',
        'admin.login' => 'Prihlásenie',
        'admin.logout' => 'Odhlásenie',
        'admin.login_failed' => 'Neúspešný pokus o prihlásenie',
        'admin.export' => 'Export do CSV',
    ];
    return ($texts[$event['action']] ?? $event['action']) . ($event['detail'] !== null && $event['detail'] !== '' ? ': ' . $event['detail'] : '');
}

if ($action === 'log') {
    $rows = '';
    foreach ($pdo->query('SELECT * FROM ca_events ORDER BY id DESC LIMIT 200')->fetchAll() as $event) {
        $rows .= '<tr><td>' . ca_e(ca_local($event['at'])) . '</td><td>' . ca_e($event['actor']) . '</td><td>' . ca_e(event_text($event))
            . ($event['order_id'] ? ' <a href="?a=order&amp;id=' . (int) $event['order_id'] . '">zobraziť</a>' : '') . '</td></tr>';
    }
    page('Záznam aktivít', '<h1>Záznam aktivít</h1><p class="muted">Posledných 200 udalostí. Prihlásenia a exporty sa po 12 mesiacoch mažú.</p>'
        . '<div class="table-wrap"><table><thead><tr><th>Kedy</th><th>Kto</th><th>Čo</th></tr></thead><tbody>'
        . ($rows ?: '<tr><td colspan="3">Zatiaľ nič.</td></tr>') . '</tbody></table></div>', $user);
}

// ---- Confirmation pages for erasing data -------------------------------------------------------

if ($action === 'confirm') {
    $order = load_order($pdo, $_GET['id'] ?? '');
    $what = $_GET['do'] ?? '';
    if ($order === null || !in_array($what, ['anonymize', 'delete'], true)) {
        not_found($user);
    }
    $title = $what === 'delete' ? 'Vymazať objednávku č. ' . $order['number'] . '?' : 'Vymazať osobné údaje z objednávky č. ' . $order['number'] . '?';
    $text = $what === 'delete'
        ? 'Objednávka sa vymaže celá, vrátane histórie. Použite to na skúšobné alebo omylom vytvorené objednávky. Nedá sa to vrátiť späť.'
        : 'Vymaže sa meno, e-mail, telefón, adresa a poznámka zákazníka. Kniha, suma a dátumy zostanú kvôli evidencii. Nedá sa to vrátiť späť.';
    page($title, '<section class="card narrow"><h1>' . ca_e($title) . '</h1><p>' . ca_e($text) . '</p>'
        . '<form method="post" action="?a=' . $what . '" class="row">' . csrf_field() . '<input type="hidden" name="id" value="' . $order['id'] . '">'
        . '<button class="button danger">Áno, vymazať</button><a class="button ghost" href="?a=order&amp;id=' . $order['id'] . '">Zrušiť</a></form></section>', $user);
}

// ---- Order detail ------------------------------------------------------------------------------

if ($action === 'order') {
    $order = load_order($pdo, $_GET['id'] ?? '');
    if ($order === null) {
        not_found($user);
    }
    $customer = $order['customer'];
    $gone = $order['anonymized_at'] !== null;

    // All four states side by side: the current one is filled and marked, the others are plain buttons that switch to it.
    $buttons = '';
    foreach (CA_STATUSES as $key => $label) {
        if ($key === $order['status']) {
            $buttons .= '<span class="state on st-' . $key . '" aria-current="true">✓ ' . ca_e($label) . '</span>';
        } else {
            $buttons .= '<form method="post" action="?a=status">' . csrf_field() . '<input type="hidden" name="id" value="' . $order['id'] . '">'
                . '<input type="hidden" name="to" value="' . $key . '"><button class="state" title="Zmeniť stav na: ' . ca_e($label) . '">' . ca_e($label) . '</button></form>';
        }
    }

    $price = ca_money($order['total_cents']);
    if ($order['unit_cents'] !== null) {
        $price .= ' <span class="muted">(' . ca_e(ca_price_breakdown($order['unit_cents'], (int) $order['quantity'], $order['shipping_cents'])) . ')</span>';
    }

    if ($gone) {
        $who = '<p class="muted">Osobné údaje boli vymazané ' . ca_e(ca_local($order['anonymized_at'])) . '.</p>';
    } else {
        $email = (string) ($customer['email'] ?? '');
        $mailto = 'mailto:' . rawurlencode($email) . '?subject=' . rawurlencode('Objednávka č. ' . $order['number'] . ' – Coaching Academy');
        $who = '<dl>'
            . '<dt>Meno</dt><dd>' . ca_e($customer['name'] ?? '') . '</dd>'
            . '<dt>E-mail</dt><dd><a href="' . ca_e($mailto) . '">' . ca_e($email) . '</a></dd>'
            . (($customer['phone'] ?? '') !== '' ? '<dt>Telefón</dt><dd>' . ca_e($customer['phone']) . '</dd>' : '')
            . (($customer['street'] ?? '') !== ''
                ? '<dt>Adresa</dt><dd>' . ca_e($customer['street']) . '<br>' . ca_e(($customer['zip'] ?? '') . ' ' . ($customer['city'] ?? '')) . '</dd>'
                : '')
            . (($customer['note'] ?? '') !== '' ? '<dt>Poznámka</dt><dd class="pre">' . ca_e($customer['note']) . '</dd>' : '')
            . '</dl><p><a class="button ghost" href="' . ca_e($mailto) . '">Napísať zákazníkovi</a></p>';
    }

    $history = '';
    $events = $pdo->prepare('SELECT * FROM ca_events WHERE order_id = ? ORDER BY id');
    $events->execute([$order['id']]);
    foreach ($events->fetchAll() as $event) {
        $history .= '<li><span class="muted">' . ca_e(ca_local($event['at'])) . '</span> ' . ca_e(event_text($event))
            . ($event['actor'] !== 'web' ? ' <span class="muted">(' . ca_e($event['actor']) . ')</span>' : '') . '</li>';
    }

    page('Objednávka č. ' . $order['number'], '<p><a href="./' . ($filterQuery ? '?' . ca_e($filterQuery) : '') . '">← Všetky objednávky</a></p>'
        . '<h1>Objednávka č. ' . ca_e($order['number']) . ' ' . status_badge($order['status']) . '</h1>'
        . '<p class="muted">Prijatá ' . ca_e(ca_local($order['created_at'])) . '</p>'
        . ($order['price_note'] ? '<p class="alert" role="alert">' . ca_e($order['price_note']) . '</p>' : '')
        . '<div class="grid"><section class="card"><h2>Objednávka</h2><dl>'
        . '<dt>Kniha</dt><dd>' . ca_e($order['item']) . '</dd><dt>Počet kusov</dt><dd>' . (int) $order['quantity'] . '</dd>'
        . '<dt>Doručenie</dt><dd>' . ca_e($order['delivery']) . '</dd>'
        . '<dt>Suma</dt><dd>' . $price . '</dd><dt>Platba</dt><dd>prevodom, variabilný symbol ' . ca_e($order['number']) . '</dd></dl>'
        . '<h3>Stav objednávky</h3><div class="row">' . $buttons . '</div>'
        . '<p class="muted hint">Teraz je ' . ca_e(mb_strtolower(CA_STATUSES[$order['status']] ?? $order['status'])) . '. Kliknutím na iný stav ho zmeníte.</p></section>'
        . '<section class="card"><h2>Zákazník</h2>' . $who . '</section></div>'
        . '<section class="card"><h2>História</h2><ul class="history">' . $history . '</ul></section>'
        . '<section class="card quiet"><h2>Ochrana osobných údajov</h2>'
        . '<p class="muted">Osobné údaje sa vymažú automaticky ' . (int) $config['retention_months'] . ' mesiacov po odoslaní objednávky ('
        . (int) $config['retention_cancelled_months'] . ' mesiacov po zrušení). Ak zákazník požiada o výmaz skôr, urobte to tu.</p><div class="row">'
        . ($gone ? '' : '<a class="button ghost" href="?a=confirm&amp;do=anonymize&amp;id=' . $order['id'] . '">Vymazať osobné údaje</a>')
        . '<a class="button ghost danger" href="?a=confirm&amp;do=delete&amp;id=' . $order['id'] . '">Vymazať celú objednávku</a></div></section>', $user);
}

// ---- Order list --------------------------------------------------------------------------------

$counts = array_fill_keys(array_keys(CA_STATUSES), 0);
foreach ($pdo->query('SELECT status, COUNT(*) AS n FROM ca_orders GROUP BY status')->fetchAll() as $row) {
    if (isset($counts[$row['status']])) {
        $counts[$row['status']] = (int) $row['n'];
    }
}

$orders = find_orders($pdo, $filterStatus, $search);
$total = count($orders);
$pages = max(1, (int) ceil($total / PAGE_SIZE));
$pageNumber = min($pages, max(1, (int) ($_GET['p'] ?? 1)));
$orders = array_slice($orders, ($pageNumber - 1) * PAGE_SIZE, PAGE_SIZE);

$tabs = '<a class="tab' . ($filterStatus === '' ? ' on' : '') . '" href="./' . ($search !== '' ? '?' . ca_e(http_build_query(['q' => $search])) : '') . '">Všetky <b>' . array_sum($counts) . '</b></a>';
foreach (['new' => 'Nové', 'paid' => 'Zaplatené', 'shipped' => 'Odoslané', 'cancelled' => 'Zrušené'] as $key => $label) {
    $tabs .= '<a class="tab' . ($filterStatus === $key ? ' on' : '') . '" href="?' . ca_e(http_build_query(array_filter(['s' => $key, 'q' => $search], 'strlen'))) . '">'
        . ca_e($label) . ' <b>' . $counts[$key] . '</b></a>';
}

$rows = '';
foreach ($orders as $order) {
    $customer = $order['customer'];
    $link = '?a=order&amp;id=' . $order['id'] . ($filterQuery ? '&amp;' . ca_e($filterQuery) : '');
    $rows .= '<tr><td data-label="Číslo"><a href="' . $link . '"><b>' . ca_e($order['number']) . '</b></a></td>'
        . '<td data-label="Dátum">' . ca_e(ca_local($order['created_at'], 'j. n. Y')) . '</td>'
        . '<td data-label="Kniha">' . ca_e($order['item']) . ' × ' . (int) $order['quantity']
        . ' <span class="muted">· ' . ca_e(mb_strtolower($order['delivery'])) . '</span>'
        . ($order['price_note'] ? ' <span class="warn" title="' . ca_e($order['price_note']) . '">skontrolovať cenu</span>' : '') . '</td>'
        . '<td data-label="Suma" class="num">' . ca_money($order['total_cents']) . '</td>'
        . '<td data-label="Zákazník">' . ($order['anonymized_at'] !== null ? '<span class="muted">údaje vymazané</span>'
            : ca_e($customer['name'] ?? '') . (($customer['city'] ?? '') !== '' ? '<span class="muted">, ' . ca_e($customer['city']) . '</span>' : '')) . '</td>'
        . '<td data-label="Stav">' . status_badge($order['status']) . '</td></tr>';
}

$pager = '';
if ($pages > 1) {
    $base = array_filter(['s' => $filterStatus, 'q' => $search], 'strlen');
    $pager = '<p class="pager">'
        . ($pageNumber > 1 ? '<a class="button ghost" href="?' . ca_e(http_build_query($base + ['p' => $pageNumber - 1])) . '">← Novšie</a>' : '')
        . '<span class="muted">Strana ' . $pageNumber . ' z ' . $pages . '</span>'
        . ($pageNumber < $pages ? '<a class="button ghost" href="?' . ca_e(http_build_query($base + ['p' => $pageNumber + 1])) . '">Staršie →</a>' : '')
        . '</p>';
}

page('Objednávky', '<h1>Objednávky kníh</h1><div class="tabs">' . $tabs . '</div>'
    . '<form method="get" action="./" class="search">' . ($filterStatus !== '' ? '<input type="hidden" name="s" value="' . ca_e($filterStatus) . '">' : '')
    . '<input name="q" value="' . ca_e($search) . '" placeholder="Hľadať meno, e-mail, mesto alebo číslo" aria-label="Hľadať">'
    . '<button class="button">Hľadať</button>' . ($search !== '' ? '<a class="button ghost" href="./' . ($filterStatus !== '' ? '?s=' . ca_e($filterStatus) : '') . '">Zrušiť</a>' : '')
    . '<a class="button ghost push" href="?' . ca_e(http_build_query(array_filter(['a' => 'export', 's' => $filterStatus, 'q' => $search], 'strlen'))) . '">Stiahnuť ako CSV (Excel)</a></form>'
    . '<div class="table-wrap"><table class="orders"><thead><tr><th>Číslo</th><th>Dátum</th><th>Kniha</th><th class="num">Suma</th><th>Zákazník</th><th>Stav</th></tr></thead><tbody>'
    . ($rows ?: '<tr><td colspan="6" class="empty">' . ($search !== '' || $filterStatus !== '' ? 'Nič sa nenašlo.' : 'Zatiaľ žiadne objednávky.') . '</td></tr>')
    . '</tbody></table></div>' . $pager
    . '<p class="muted foot">Prihlásený: ' . ca_e($user) . ' · IP ' . ca_e((string) ($_SERVER['REMOTE_ADDR'] ?? '?')) . ' · PHP ' . ca_e(PHP_VERSION)
    . ' · cenník z webu: ' . (ca_catalog() ? 'načítaný' : 'NEDOSTUPNÝ') . ' · e-mail: ' . (!empty($config['smtp']['host']) ? 'SMTP' : 'PHP mail()') . '</p>', $user);
