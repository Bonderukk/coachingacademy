<?php
/**
 * Form endpoint for coachingacademy.sk (book orders, contact, withdrawal).
 *
 * The website itself is static (GitHub Pages), so its forms POST here. This file lives on the
 * Websupport webhosting (dalibus_1) under the domain coachacademy.sk and e-mails each submission
 * to Dad. The site's PUBLIC_FORM_ENDPOINT points at https://coachacademy.sk/form.php.
 *
 * Optional settings live in form-config.php next to this file (see form-config.example.php). That
 * file stays on the server only and is not in git, because it holds the recipients and the SMTP password.
 *
 * The forms send the same fields Formspree would: `_subject` (e-mail subject), `_gotcha` (honeypot),
 * `email` (the visitor's address, used as Reply-To) and the visible fields.
 * Works on PHP 7.4+.
 */

// ---- Settings ----------------------------------------------------------------------------------

// Defaults; form-config.php can override them.
$config = [
    // One address or a list.
    'mail_to' => 'dalibor@coachingacademy.sk',
    // Sender used with PHP mail(). With SMTP the sender is the SMTP mailbox.
    'mail_from' => 'web@coachacademy.sk',
    'mail_from_name' => 'Web coachingacademy.sk',
    // Authenticated SMTP (recommended): ['host' => 'smtp.m1.websupport.sk', 'port' => 465, 'user' => '…', 'pass' => '…'].
    // Without it, PHP mail() is used.
    'smtp' => null,
];
if (is_readable(__DIR__ . '/form-config.php')) {
    $config = array_merge($config, (array) require __DIR__ . '/form-config.php');
}

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

// ---- Helpers -----------------------------------------------------------------------------------

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

/**
 * Sends a message over authenticated SMTP (implicit TLS, port 465). Returns null on success,
 * otherwise a short reason for the server log.
 */
function smtp_send(array $smtp, string $from, array $to, string $message): ?string
{
    $host = $smtp['host'];
    $port = (int) ($smtp['port'] ?? 465);
    $socket = @stream_socket_client("ssl://$host:$port", $errno, $errstr, 20, STREAM_CLIENT_CONNECT,
        stream_context_create(['ssl' => ['peer_name' => $host]]));
    if (!$socket) {
        return "connect: $errstr";
    }
    stream_set_timeout($socket, 20);

    // Reads one (possibly multi-line) reply and checks its status code.
    $expect = function (string $code) use ($socket): ?string {
        $reply = '';
        while (($line = fgets($socket, 1024)) !== false) {
            $reply .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        return strncmp($reply, $code, 3) === 0 ? null : trim($reply);
    };
    $command = function (string $line, string $code) use ($socket, $expect): ?string {
        fwrite($socket, $line . "\r\n");
        return $expect($code);
    };

    $steps = [
        fn () => $expect('220'),
        fn () => $command('EHLO coachacademy.sk', '250'),
        fn () => $command('AUTH LOGIN', '334'),
        fn () => $command(base64_encode($smtp['user']), '334'),
        fn () => $command(base64_encode($smtp['pass']), '235'),
        fn () => $command("MAIL FROM:<$from>", '250'),
    ];
    foreach ($to as $recipient) {
        $steps[] = fn () => $command("RCPT TO:<$recipient>", '250');
    }
    $steps[] = fn () => $command('DATA', '354');
    // Dot-stuffing: a line starting with "." must be doubled inside DATA.
    $steps[] = fn () => $command(preg_replace('/^\./m', '..', $message) . "\r\n.", '250');

    foreach ($steps as $i => $step) {
        if (($error = $step()) !== null) {
            fclose($socket);
            return "step $i: $error";
        }
    }
    fwrite($socket, "QUIT\r\n");
    fclose($socket);
    return null;
}

// ---- Request handling --------------------------------------------------------------------------

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

$to = array_values(array_filter((array) $config['mail_to'], function ($a) { return filter_var($a, FILTER_VALIDATE_EMAIL); }));
$smtp = is_array($config['smtp']) && !empty($config['smtp']['host']) ? $config['smtp'] : null;
$from = $smtp ? $smtp['user'] : $config['mail_from'];
$fromHeader = encode_header($config['mail_from_name']) . " <$from>";

if ($smtp) {
    $message = implode("\r\n", [
        'Date: ' . date('r'),
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@coachacademy.sk>',
        "From: $fromHeader",
        'To: ' . implode(', ', $to),
        "Reply-To: $visitorEmail",
        'Subject: ' . encode_header($subject),
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
        '',
        rtrim(chunk_split(base64_encode($body), 76, "\r\n")),
    ]);
    $error = smtp_send($smtp, $from, $to, $message);
    $sent = $error === null;
    if (!$sent) {
        error_log("form.php SMTP failed: $error");
    }
} else {
    $headers = implode("\r\n", [
        "From: $fromHeader",
        "Reply-To: $visitorEmail",
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ]);
    $sent = mail(implode(', ', $to), encode_header($subject), $body, $headers, '-f' . $from);
}

if (!$sent) {
    respond(500, ['ok' => false, 'error' => 'mail']);
}

$recent[] = $now;
@file_put_contents($rateFile, implode(',', $recent), LOCK_EX);
respond(200, ['ok' => true]);
