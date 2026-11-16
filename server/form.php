<?php
/**
 * Form endpoint for coachingacademy.sk (book orders, contact, withdrawal).
 *
 * The website itself is static (GitHub Pages), so its forms POST here. This file lives on the
 * Websupport webhosting (dalibus_1) under the domain coachacademy.sk and e-mails each submission
 * to Dad. The site's PUBLIC_FORM_ENDPOINT points at https://coachacademy.sk/form.php.
 *
 * The forms send the same fields Formspree would: `_subject` (e-mail subject), `_gotcha` (honeypot),
 * `email` (the visitor's address, used as Reply-To) and the visible fields.
 * Works on PHP 7.4+.
 */

// ---- Settings ----------------------------------------------------------------------------------

const MAIL_TO = 'dalibor@coachingacademy.sk';
// Sender on this hosting's own domain, so SPF passes. Replies go to the visitor (Reply-To).
const MAIL_FROM = 'web@coachacademy.sk';
const MAIL_FROM_NAME = 'Web coachingacademy.sk';

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

// ---- Request handling --------------------------------------------------------------------------

date_default_timezone_set('Europe/Bratislava');

function respond(int $status, array $body): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

/** One line of text: no line breaks (prevents e-mail header injection), trimmed and capped. */
function one_line(string $value, int $max = 200): string
{
    return mb_substr(trim(preg_replace('/[\r\n\t]+/', ' ', $value)), 0, $max);
}

/** Encodes a header value as UTF-8 (RFC 2047) so Slovak characters survive. */
function encode_header(string $value): string
{
    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}

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

// Bots fill the hidden honeypot field: pretend success and send nothing.
if (!empty($_POST['_gotcha'])) {
    respond(200, ['ok' => true]);
}

// Simple per-IP rate limit, stored in the system temp directory.
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rateFile = sys_get_temp_dir() . '/ca-form-' . sha1($ip);
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

// Validate the visitor's e-mail (every form asks for it).
$visitorEmail = one_line((string) ($_POST['email'] ?? ''), 254);
if (!filter_var($visitorEmail, FILTER_VALIDATE_EMAIL)) {
    respond(422, ['ok' => false, 'error' => 'email']);
}

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
    $label = LABELS[$key] ?? one_line($key, 60);
    $value = mb_substr(trim(str_replace("\r\n", "\n", $value)), 0, 5000);
    if ($key === 'suhlas') {
        $value = 'áno';
    }
    if ($value === '') {
        continue;
    }
    $lines[] = strpos($value, "\n") === false ? "$label: $value" : "$label:\n$value\n";
}

$subject = one_line((string) ($_POST['_subject'] ?? ''), 150);
if ($subject === '') {
    $subject = 'Správa z webu coachingacademy.sk';
}

$body = implode("\n", $lines)
    . "\n\n--\nOdoslané z webu coachingacademy.sk " . date('j. n. Y H:i')
    . "\nNa tento e-mail môžete priamo odpovedať, odpoveď pôjde odosielateľovi.\n";

$headers = implode("\r\n", [
    'From: ' . encode_header(MAIL_FROM_NAME) . ' <' . MAIL_FROM . '>',
    'Reply-To: ' . $visitorEmail,
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'Content-Transfer-Encoding: 8bit',
]);

$sent = mail(MAIL_TO, encode_header($subject), $body, $headers, '-f' . MAIL_FROM);
if (!$sent) {
    respond(500, ['ok' => false, 'error' => 'mail']);
}

$recent[] = $now;
@file_put_contents($rateFile, implode(',', $recent), LOCK_EX);
respond(200, ['ok' => true]);
