<?php
/**
 * Form endpoint for coachingacademy.sk (book orders, contact, withdrawal).
 *
 * The website itself is static (GitHub Pages), so its forms POST here. This file lives on the
 * Websupport webhosting (dalibus_1) under the domain coachacademy.sk. The site's PUBLIC_FORM_ENDPOINT
 * points at https://coachacademy.sk/form.php.
 *
 * - Book orders (`_form=order`) are priced from the website's price list, stored in the database with
 *   the customer's data encrypted, and e-mailed to Dad. They show up in the administration (admin/).
 * - Contact and withdrawal messages are only e-mailed.
 *
 * Settings live in form-config.php (see form-config.example.php), which stays on the server only.
 * The forms follow Formspree's conventions: `_subject` (e-mail subject), `_gotcha` (honeypot),
 * `email` (the visitor's address, used as Reply-To) and the visible fields.
 */

define('CA_APP', true);
require __DIR__ . '/lib/app.php';

// Pages allowed to submit (the live site, the GitHub Pages preview, local development).
const ALLOWED_ORIGINS = [
    'https://coachingacademy.sk',
    'https://www.coachingacademy.sk',
    'https://bonderukk.github.io',
    'http://localhost:4321',
];

// At most this many submissions per visitor IP within the window (spam brake).
const RATE_LIMIT = 5;
const RATE_WINDOW_SECONDS = 15 * 60;

// Readable labels for the e-mail; fields not listed here are shown under their own name.
const LABELS = [
    'kniha' => 'Kniha',
    'pocet_kusov' => 'Počet kusov',
    'cena_spolu' => 'Cena spolu',
    'meno' => 'Meno a priezvisko',
    'email' => 'E-mail',
    'telefon' => 'Telefón',
    'ulica' => 'Ulica a číslo domu',
    'psc' => 'PSČ',
    'mesto' => 'Mesto / obec',
    'poznamka' => 'Poznámka',
    'tema' => 'Mám záujem o',
    'sprava' => 'Správa',
    'cislo_objednavky' => 'Číslo objednávky',
    'datum' => 'Dátum objednávky / prevzatia',
    'suhlas' => 'Súhlas s podmienkami',
];

function respond(int $status, array $body): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

/** A posted field as a string ('' when missing or not a plain value). */
function posted(string $key): string
{
    return is_string($_POST[$key] ?? null) ? $_POST[$key] : '';
}

/** "25 €" or "1 250,50 €" as cents; null when it is not a number. */
function money_to_cents(string $text): ?int
{
    $number = str_replace(',', '.', (string) preg_replace('/[^0-9,.]/', '', $text));
    return is_numeric($number) ? (int) round($number * 100) : null;
}

$footer = "\n\n--\nOdoslané z webu coachingacademy.sk " . date('j. n. Y H:i')
    . "\nNa tento e-mail môžete priamo odpovedať, odpoveď pôjde odosielateľovi.\n";

// ---- Who may call us ---------------------------------------------------------------------------

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, ALLOWED_ORIGINS, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Accept, Content-Type');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(405, ['ok' => false, 'error' => 'method']);
}
if (!in_array($origin, ALLOWED_ORIGINS, true)) {
    respond(403, ['ok' => false, 'error' => 'origin']);
}

// Bots fill the hidden honeypot field: pretend success and do nothing.
if (!empty($_POST['_gotcha'])) {
    respond(200, ['ok' => true]);
}

// Simple per-IP rate limit, stored in the temp directory under a keyed hash of the address.
$rateFile = sys_get_temp_dir() . '/ca-form-' . ca_ip_hash();
$now = time();
$recent = [];
if (is_readable($rateFile)) {
    $recent = array_filter(
        array_map('intval', explode(',', (string) file_get_contents($rateFile))),
        function ($t) use ($now) { return $t > $now - RATE_WINDOW_SECONDS; }
    );
}
if (count($recent) >= RATE_LIMIT) {
    respond(429, ['ok' => false, 'error' => 'rate']);
}
// Remove the records of visitors whose window has passed, so address hashes do not pile up.
foreach (glob(sys_get_temp_dir() . '/ca-form-*') ?: [] as $stale) {
    if (@filemtime($stale) < $now - RATE_WINDOW_SECONDS) {
        @unlink($stale);
    }
}

// Every form asks for the visitor's e-mail.
$visitorEmail = ca_one_line(posted('email'), 254);
if (!filter_var($visitorEmail, FILTER_VALIDATE_EMAIL)) {
    respond(422, ['ok' => false, 'error' => 'invalid', 'field' => 'email']);
}

// ---- Book order --------------------------------------------------------------------------------

if (posted('_form') === 'order') {
    $quantity = filter_var(posted('pocet_kusov'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 20]]);
    $customer = [
        'name' => ca_one_line(posted('meno'), 120),
        'email' => $visitorEmail,
        'phone' => ca_one_line(posted('telefon'), 40),
        'street' => ca_one_line(posted('ulica'), 150),
        'zip' => ca_one_line(posted('psc'), 12),
        'city' => ca_one_line(posted('mesto'), 100),
        'note' => ca_text(posted('poznamka'), 2000),
    ];
    if ($quantity === false) {
        respond(422, ['ok' => false, 'error' => 'invalid', 'field' => 'pocet_kusov']);
    }
    if ($customer['name'] === '') {
        respond(422, ['ok' => false, 'error' => 'invalid', 'field' => 'meno']);
    }
    if (posted('suhlas') === '') {
        respond(422, ['ok' => false, 'error' => 'invalid', 'field' => 'suhlas']);
    }

    // Price the order from the website's price list, never from what the browser sent.
    $productId = ca_one_line(posted('_produkt'), 80);
    $variantIndex = filter_var(posted('_variant'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 50]]);
    $claimedTotal = ca_one_line(posted('cena_spolu'), 40);
    $product = null;
    $variant = null;
    $catalog = ca_catalog();
    foreach ($catalog['products'] ?? [] as $candidate) {
        if ($candidate['id'] === $productId && $variantIndex !== false && isset($candidate['variants'][$variantIndex])) {
            $product = $candidate;
            $variant = $candidate['variants'][$variantIndex];
        }
    }

    if ($variant !== null) {
        $digital = !empty($variant['digital']);
        $unit = (int) round($variant['price'] * 100);
        $shipping = $digital ? 0 : (int) round($catalog['shipping'] * 100);
        $order = [
            'product_id' => $productId,
            'item' => mb_substr(count($product['variants']) > 1 ? $product['title'] . ' – ' . $variant['label'] : $product['title'], 0, 255),
            'digital' => $digital,
            'quantity' => $quantity,
            'unit_cents' => $unit,
            'shipping_cents' => $shipping,
            'total_cents' => $unit * $quantity + $shipping,
            'price_note' => null,
        ];
        if ($claimedTotal !== '' && money_to_cents($claimedTotal) !== $order['total_cents']) {
            $order['price_note'] = "Zákazníkovi sa na webe zobrazila iná suma ($claimedTotal). Skontrolujte cenu.";
        }
    } else {
        // The price list could not be loaded, or it does not know this book. Never lose the order:
        // keep what the browser sent and flag it for a manual check.
        $digital = $customer['street'] === '' && $customer['city'] === '';
        $order = [
            'product_id' => $productId,
            'item' => ca_one_line(posted('kniha'), 255) ?: 'Kniha (neuvedená)',
            'digital' => $digital,
            'quantity' => $quantity,
            'unit_cents' => null,
            'shipping_cents' => null,
            'total_cents' => money_to_cents($claimedTotal),
            'price_note' => 'Cenu sa nepodarilo overiť podľa cenníka na webe. Skontrolujte ju.',
        ];
    }
    if (!$order['digital'] && ($customer['street'] === '' || $customer['zip'] === '' || $customer['city'] === '')) {
        respond(422, ['ok' => false, 'error' => 'invalid', 'field' => 'adresa']);
    }

    // Store it. A database problem must not stop the order: it still goes out by e-mail.
    $stored = null;
    $pdo = null;
    try {
        $pdo = ca_db();
        if ($pdo) {
            $stored = ca_order_create($pdo, $order, $customer);
        }
    } catch (Throwable $e) {
        error_log('form.php: order not stored: ' . $e->getMessage());
    }

    // With mail_order_details off, a stored order is announced without personal data: the customer is in the
    // administration. An order that could not be stored always goes out in full, otherwise it would be lost.
    $withDetails = !$stored || !empty(ca_config()['mail_order_details']);
    $lines = [
        'Nová objednávka z webu coachingacademy.sk',
        '',
        $stored ? 'Číslo objednávky: ' . $stored['number'] : null,
        'Kniha: ' . $order['item'],
        'Počet kusov: ' . $quantity,
        'Cena spolu: ' . ca_money($order['total_cents'])
            . ($order['unit_cents'] !== null
                ? ' (' . ca_money($order['unit_cents']) . ' × ' . $quantity . ($order['digital'] ? ', bez poštovného' : ' + poštovné ' . ca_money($order['shipping_cents'])) . ')'
                : ''),
        $order['price_note'] ? 'POZOR: ' . $order['price_note'] : null,
        '',
    ];
    if ($withDetails) {
        array_push(
            $lines,
            'Meno a priezvisko: ' . $customer['name'],
            'E-mail: ' . $customer['email'],
            $customer['phone'] !== '' ? 'Telefón: ' . $customer['phone'] : null,
            $order['digital'] ? null : 'Adresa: ' . $customer['street'] . ', ' . $customer['zip'] . ' ' . $customer['city'],
            $customer['note'] !== '' ? "Poznámka:\n" . $customer['note'] : null,
            ''
        );
    }
    $lines[] = $stored
        ? ($withDetails ? 'Objednávka v administrácii: ' : 'Údaje zákazníka nájdete v administrácii: ') . rtrim((string) ca_config()['admin_url'], '/') . '/?a=order&id=' . $stored['id']
        : (ca_config()['db'] ? 'POZOR: Objednávku sa nepodarilo uložiť do administrácie, je iba v tomto e-maile.' : null);
    $body = implode("\n", array_filter($lines, function ($line) { return $line !== null; }))
        . ($withDetails ? $footer : "\n\n--\nOdoslané z webu coachingacademy.sk " . date('j. n. Y H:i') . "\n");
    $subject = ($stored ? 'Objednávka č. ' . $stored['number'] : 'Objednávka knihy') . ': ' . $order['item'] . ' × ' . $quantity;
    $sent = ca_send_mail($subject, $body, $withDetails ? $visitorEmail : '');

    if (!$stored && !$sent) {
        respond(500, ['ok' => false, 'error' => 'mail']);
    }
    if ($pdo && $stored) {
        try {
            if (!$sent) {
                ca_event($pdo, 'web', 'order.mail_failed', $stored['id']);
            }
            ca_retention($pdo);
        } catch (Throwable $e) {
            error_log('form.php: ' . $e->getMessage());
        }
    }
    $recent[] = $now;
    @file_put_contents($rateFile, implode(',', $recent), LOCK_EX);
    respond(200, $stored ? ['ok' => true, 'order' => $stored['number']] : ['ok' => true]);
}

// ---- Contact and withdrawal messages -----------------------------------------------------------

// Collect the visible fields in the order they were sent.
$lines = [];
$fieldCount = 0;
foreach ($_POST as $key => $value) {
    if (!is_string($key) || $key === '' || $key[0] === '_' || !is_string($value)) {
        continue;
    }
    if (++$fieldCount > 30) {
        break;
    }
    $label = LABELS[$key] ?? ca_one_line($key, 60);
    $value = ca_text($value);
    if ($key === 'suhlas') {
        $value = 'áno';
    }
    if ($value === '') {
        continue;
    }
    $lines[] = strpos($value, "\n") === false ? "$label: $value" : "$label:\n$value\n";
}

$subject = ca_one_line(posted('_subject'), 150);
if ($subject === '') {
    $subject = 'Správa z webu coachingacademy.sk';
}

if (!ca_send_mail($subject, implode("\n", $lines) . $footer, $visitorEmail)) {
    respond(500, ['ok' => false, 'error' => 'mail']);
}

$recent[] = $now;
@file_put_contents($rateFile, implode(',', $recent), LOCK_EX);
respond(200, ['ok' => true]);
