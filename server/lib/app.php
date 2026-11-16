<?php
/**
 * Shared code for form.php and admin/index.php: settings, database, encryption, e-mail, price list, orders.
 *
 * It only defines functions. The entry scripts define CA_APP before including it; opened directly it does nothing.
 * Written for PHP 7.4+ (no PHP 8-only syntax), because the hosting's PHP version can change.
 */

if (!defined('CA_APP')) {
    http_response_code(404);
    exit;
}

date_default_timezone_set('Europe/Bratislava');
ini_set('display_errors', '0');
mb_internal_encoding('UTF-8');
// Do not advertise the PHP version.
header_remove('X-Powered-By');

/** Order states in the order they normally happen. */
const CA_STATUSES = ['new' => 'Nová', 'paid' => 'Zaplatená', 'shipped' => 'Odoslaná', 'cancelled' => 'Zrušená'];

// ---- Settings ----------------------------------------------------------------------------------

/** Defaults merged with form-config.php (see form-config.example.php). */
function ca_config(): array
{
    static $config = null;
    if ($config === null) {
        $config = [
            // Who receives orders and messages: one address or a list.
            'mail_to' => 'dalibor@coachingacademy.sk',
            // Sender used with PHP mail(). With SMTP the sender is the SMTP mailbox.
            'mail_from' => 'web@coachacademy.sk',
            'mail_from_name' => 'Web coachingacademy.sk',
            // false = the e-mail about a stored order carries no personal data, only its number and a link to the administration.
            'mail_order_details' => true,
            // ['host' => …, 'port' => 465, 'user' => …, 'pass' => …]; without it PHP mail() is used.
            'smtp' => null,
            // ['host' => …, 'port' => 3306, 'name' => …, 'user' => …, 'pass' => …]; without it orders are only e-mailed.
            'db' => null,
            // Written by tools/setup.php: the key that encrypts customer data, and the admin logins.
            'data_key' => '',
            'admins' => [],
            // The public website; its /catalog.json is the price list orders are checked against.
            'site_url' => 'https://coachingacademy.sk',
            'catalog_url' => null,
            // Where the administration lives. Fixed here (not taken from the request) so e-mailed links cannot be forged.
            'admin_url' => 'https://coachacademy.sk/admin',
            // Personal data in an order is erased this many months after it was shipped / cancelled.
            'retention_months' => 36,
            'retention_cancelled_months' => 6,
        ];
        // Preferred place is one level above the web root; next to form.php works too.
        foreach ([dirname(__DIR__, 2), dirname(__DIR__)] as $dir) {
            $file = $dir . '/form-config.php';
            if (@is_readable($file)) {
                $config = array_merge($config, (array) require $file);
                break;
            }
        }
    }
    return $config;
}

// ---- Small helpers -----------------------------------------------------------------------------

/** One line of text: no line breaks (prevents e-mail header injection), trimmed and capped. */
function ca_one_line(string $value, int $max = 200): string
{
    return mb_substr(trim((string) preg_replace('/[\r\n\t]+/', ' ', $value)), 0, $max);
}

/** Multi-line text with normalised line breaks, trimmed and capped. */
function ca_text(string $value, int $max = 5000): string
{
    return mb_substr(trim(str_replace(["\r\n", "\r"], "\n", $value)), 0, $max);
}

/** Escapes text for HTML output. */
function ca_e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Current time as stored in the database (UTC). */
function ca_now(): string
{
    return gmdate('Y-m-d H:i:s');
}

/** A stored UTC time shown in Slovak local time, e.g. "16. 11. 2026 14:05". */
function ca_local(?string $utc, string $format = 'j. n. Y H:i'): string
{
    if (!$utc) {
        return '';
    }
    $time = new DateTime($utc, new DateTimeZone('UTC'));
    return $time->setTimezone(new DateTimeZone('Europe/Bratislava'))->format($format);
}

/** Cents as a Slovak price: 2500 → "25 €", 2550 → "25,50 €". */
function ca_money(?int $cents): string
{
    if ($cents === null) {
        return '–';
    }
    return number_format($cents / 100, $cents % 100 ? 2 : 0, ',', ' ') . ' €';
}

/** True when the visitor reached us over HTTPS (directly or through the hosting's proxy). */
function ca_is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443'
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

/** The request's host name if it looks like one, otherwise the server's own name. */
function ca_host(): string
{
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    return preg_match('/^[a-z0-9.-]+(:\d+)?$/i', $host) ? $host : (string) ($_SERVER['SERVER_NAME'] ?? 'localhost');
}

// ---- Encryption of customer data ---------------------------------------------------------------

/** The 32-byte key from the settings, or null when it is missing or malformed. */
function ca_key(): ?string
{
    $key = base64_decode((string) ca_config()['data_key'], true);
    return $key !== false && strlen($key) === 32 ? $key : null;
}

/**
 * Encrypts an array (AES-256-GCM). Customer names, addresses and notes are stored only in this form,
 * so a leaked database copy alone does not reveal them.
 */
function ca_encrypt(array $data): string
{
    $key = ca_key();
    if ($key === null) {
        throw new RuntimeException('data_key is not set');
    }
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt((string) json_encode($data, JSON_UNESCAPED_UNICODE), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) {
        throw new RuntimeException('encryption failed');
    }
    return 'v1:' . base64_encode($iv . $tag . $cipher);
}

/** Reverses ca_encrypt(). Returns null for empty, damaged or foreign data. */
function ca_decrypt(?string $blob): ?array
{
    $key = ca_key();
    if ($key === null || $blob === null || strncmp($blob, 'v1:', 3) !== 0) {
        return null;
    }
    $raw = base64_decode(substr($blob, 3), true);
    if ($raw === false || strlen($raw) < 29) {
        return null;
    }
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    $data = $plain === false ? null : json_decode($plain, true);
    return is_array($data) ? $data : null;
}

/** The visitor's IP address as a keyed hash, so throttling never stores the address itself. */
function ca_ip_hash(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    return substr(hash_hmac('sha256', $ip, ca_key() ?? 'ca-no-key'), 0, 32);
}

// ---- Database ----------------------------------------------------------------------------------

/**
 * The database connection, or null when no database is configured (or it failed earlier in this request).
 * Throws PDOException on the first failed attempt so the caller can log it.
 */
function ca_db(): ?PDO
{
    static $pdo = null, $tried = false;
    if ($tried) {
        return $pdo;
    }
    $tried = true;
    $db = ca_config()['db'];
    if (!is_array($db) || empty($db['name'])) {
        return null;
    }
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'] ?? 'localhost', (int) ($db['port'] ?? 3306), $db['name']);
    $connection = new PDO($dsn, (string) ($db['user'] ?? ''), (string) ($db['pass'] ?? ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    ca_schema($connection);
    $pdo = $connection;
    return $pdo;
}

/** Creates the tables on first use. */
function ca_schema(PDO $pdo): void
{
    try {
        if ((int) $pdo->query("SELECT v FROM ca_meta WHERE k = 'schema'")->fetchColumn() >= 1) {
            return;
        }
    } catch (PDOException $e) {
        // First run: the tables do not exist yet.
    }
    $tail = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $pdo->exec('CREATE TABLE IF NOT EXISTS ca_meta (
        k VARCHAR(40) NOT NULL PRIMARY KEY,
        v VARCHAR(255) NOT NULL
    )' . $tail);
    // `customer` holds the encrypted name, e-mail, phone, address and note; everything else is not personal.
    $pdo->exec('CREATE TABLE IF NOT EXISTS ca_orders (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        created_at DATETIME NOT NULL,
        status VARCHAR(12) NOT NULL,
        status_at DATETIME NOT NULL,
        product_id VARCHAR(80) NOT NULL,
        item VARCHAR(255) NOT NULL,
        digital TINYINT(1) NOT NULL DEFAULT 0,
        quantity SMALLINT UNSIGNED NOT NULL,
        unit_cents INT UNSIGNED NULL,
        shipping_cents INT UNSIGNED NULL,
        total_cents INT UNSIGNED NULL,
        price_note VARCHAR(255) NULL,
        customer TEXT NULL,
        anonymized_at DATETIME NULL,
        KEY idx_status (status, id)
    )' . $tail);
    // What happened to orders and who signed in. Never contains customer data.
    $pdo->exec('CREATE TABLE IF NOT EXISTS ca_events (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        at DATETIME NOT NULL,
        actor VARCHAR(40) NOT NULL,
        action VARCHAR(40) NOT NULL,
        order_id INT UNSIGNED NULL,
        detail VARCHAR(255) NULL,
        KEY idx_order (order_id),
        KEY idx_at (at)
    )' . $tail);
    // Failed sign-ins per hashed IP address, kept for a day.
    $pdo->exec('CREATE TABLE IF NOT EXISTS ca_throttle (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        bucket VARCHAR(20) NOT NULL,
        ip_hash CHAR(32) NOT NULL,
        at INT UNSIGNED NOT NULL,
        KEY idx_lookup (bucket, ip_hash, at)
    )' . $tail);
    $pdo->exec("INSERT INTO ca_meta (k, v) VALUES ('schema', '1') ON DUPLICATE KEY UPDATE v = '1'");
}

/** Adds a line to the activity log. `$detail` must never contain customer data. */
function ca_event(PDO $pdo, string $actor, string $action, ?int $orderId = null, string $detail = ''): void
{
    $pdo->prepare('INSERT INTO ca_events (at, actor, action, order_id, detail) VALUES (?, ?, ?, ?, ?)')
        ->execute([ca_now(), mb_substr($actor, 0, 40), $action, $orderId, $detail === '' ? null : mb_substr($detail, 0, 255)]);
}

/** How many times this visitor hit the bucket within the last `$window` seconds. */
function ca_throttle_count(PDO $pdo, string $bucket, int $window): int
{
    $query = $pdo->prepare('SELECT COUNT(*) FROM ca_throttle WHERE bucket = ? AND ip_hash = ? AND at > ?');
    $query->execute([$bucket, ca_ip_hash(), time() - $window]);
    return (int) $query->fetchColumn();
}

function ca_throttle_hit(PDO $pdo, string $bucket): void
{
    $pdo->prepare('INSERT INTO ca_throttle (bucket, ip_hash, at) VALUES (?, ?, ?)')->execute([$bucket, ca_ip_hash(), time()]);
}

/**
 * Storage limitation (GDPR art. 5): once a day, erases the personal data of orders that were shipped or
 * cancelled longer ago than the settings allow, and drops old throttle and sign-in records.
 */
function ca_retention(PDO $pdo): void
{
    $today = gmdate('Y-m-d');
    $last = $pdo->query("SELECT v FROM ca_meta WHERE k = 'retention_run'")->fetchColumn();
    if ($last === $today) {
        return;
    }
    $pdo->prepare("INSERT INTO ca_meta (k, v) VALUES ('retention_run', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")->execute([$today]);

    $config = ca_config();
    $limits = [
        'shipped' => max(1, (int) $config['retention_months']),
        'cancelled' => max(1, (int) $config['retention_cancelled_months']),
    ];
    foreach ($limits as $status => $months) {
        $cutoff = gmdate('Y-m-d H:i:s', (int) strtotime("-$months months"));
        $old = $pdo->prepare('SELECT id FROM ca_orders WHERE status = ? AND status_at < ? AND anonymized_at IS NULL');
        $old->execute([$status, $cutoff]);
        foreach ($old->fetchAll(PDO::FETCH_COLUMN) as $id) {
            ca_order_anonymize($pdo, (int) $id, 'automaticky');
        }
    }
    $pdo->prepare('DELETE FROM ca_throttle WHERE at < ?')->execute([time() - 86400]);
    $pdo->prepare("DELETE FROM ca_events WHERE action LIKE 'admin.%' AND at < ?")->execute([gmdate('Y-m-d H:i:s', (int) strtotime('-12 months'))]);
}

// ---- Orders ------------------------------------------------------------------------------------

/** Order number shown to the customer and usable as a payment reference: two-digit year + running number. */
function ca_order_number(int $id, string $createdAtUtc): string
{
    return gmdate('y', (int) strtotime($createdAtUtc . ' UTC')) . sprintf('%04d', $id);
}

/**
 * Stores an order and returns its id and number.
 *
 * @param array $order    product_id, item, digital, quantity, unit_cents, shipping_cents, total_cents, price_note
 * @param array $customer name, email, phone, street, zip, city, note (stored encrypted)
 */
function ca_order_create(PDO $pdo, array $order, array $customer): array
{
    $now = ca_now();
    $pdo->prepare('INSERT INTO ca_orders
        (created_at, status, status_at, product_id, item, digital, quantity, unit_cents, shipping_cents, total_cents, price_note, customer)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([
            $now, 'new', $now, $order['product_id'], $order['item'], $order['digital'] ? 1 : 0, $order['quantity'],
            $order['unit_cents'], $order['shipping_cents'], $order['total_cents'], $order['price_note'], ca_encrypt($customer),
        ]);
    $id = (int) $pdo->lastInsertId();
    ca_event($pdo, 'web', 'order.created', $id);
    return ['id' => $id, 'number' => ca_order_number($id, $now)];
}

/** Erases the personal data of an order and keeps the rest (book, amount, dates) for the records. */
function ca_order_anonymize(PDO $pdo, int $id, string $actor): void
{
    $pdo->prepare('UPDATE ca_orders SET customer = NULL, anonymized_at = ? WHERE id = ? AND anonymized_at IS NULL')->execute([ca_now(), $id]);
    ca_event($pdo, $actor, 'order.anonymized', $id);
}

// ---- Price list --------------------------------------------------------------------------------

/** Fetches a URL (GET, short timeouts, at most three redirects). Returns null on any failure. */
function ca_http_get(string $url): ?string
{
    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'coachingacademy-form/1.0',
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        // Needed only before PHP 8.0; newer versions free the handle themselves and 8.5 warns about the call.
        if (PHP_VERSION_ID < 80000) {
            curl_close($curl);
        }
        return is_string($body) && $status === 200 && strlen($body) < 200000 ? $body : null;
    }
    if (ini_get('allow_url_fopen')) {
        $body = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 6, 'user_agent' => 'coachingacademy-form/1.0']]), 0, 200000);
        return is_string($body) && $body !== '' ? $body : null;
    }
    return null;
}

function ca_catalog_valid($data): bool
{
    if (!is_array($data) || !isset($data['shipping'], $data['products']) || !is_numeric($data['shipping']) || !is_array($data['products'])) {
        return false;
    }
    foreach ($data['products'] as $product) {
        if (!is_array($product) || !isset($product['id'], $product['title'], $product['variants']) || !is_array($product['variants'])) {
            return false;
        }
        foreach ($product['variants'] as $variant) {
            if (!is_array($variant) || !isset($variant['label'], $variant['price']) || !is_numeric($variant['price'])) {
                return false;
            }
        }
    }
    return count($data['products']) > 0;
}

/**
 * The website's price list (its /catalog.json), cached for ten minutes. Orders are priced from it, so a
 * visitor cannot change a price in the browser. Returns null when it cannot be loaded and no copy is cached.
 */
function ca_catalog(): ?array
{
    $config = ca_config();
    $url = $config['catalog_url'] ?: rtrim((string) $config['site_url'], '/') . '/catalog.json';
    $cache = sys_get_temp_dir() . '/ca-catalog-' . sha1($url) . '.json';
    if (!@is_readable($cache) || filemtime($cache) < time() - 600) {
        $json = ca_http_get($url);
        $data = $json === null ? null : json_decode($json, true);
        if (ca_catalog_valid($data)) {
            @file_put_contents($cache, $json, LOCK_EX);
            return $data;
        }
        // The site is unreachable: keep using the last good copy and try again in a minute.
        @touch($cache, time() - 540);
    }
    $data = @is_readable($cache) ? json_decode((string) file_get_contents($cache), true) : null;
    return ca_catalog_valid($data) ? $data : null;
}

// ---- E-mail ------------------------------------------------------------------------------------

/** Encodes a header value as UTF-8 (RFC 2047) so Slovak characters survive. */
function ca_encode_header(string $value): string
{
    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}

/**
 * Sends a message over authenticated SMTP (implicit TLS, port 465). Returns null on success,
 * otherwise a short reason for the server log.
 */
function ca_smtp_send(array $smtp, string $from, array $to, string $message): ?string
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

/** E-mails the configured recipients. `$replyTo` must already be a validated address. */
function ca_send_mail(string $subject, string $body, string $replyTo = ''): bool
{
    $config = ca_config();
    $to = array_values(array_filter((array) $config['mail_to'], function ($address) {
        return (bool) filter_var($address, FILTER_VALIDATE_EMAIL);
    }));
    if (!$to) {
        error_log('form.php: no valid recipient in mail_to');
        return false;
    }
    $smtp = is_array($config['smtp']) && !empty($config['smtp']['host']) ? $config['smtp'] : null;
    $from = $smtp ? $smtp['user'] : $config['mail_from'];
    $fromHeader = ca_encode_header($config['mail_from_name']) . " <$from>";
    $subject = ca_one_line($subject, 150);

    if ($smtp) {
        $message = implode("\r\n", array_filter([
            'Date: ' . date('r'),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@coachacademy.sk>',
            "From: $fromHeader",
            'To: ' . implode(', ', $to),
            $replyTo !== '' ? "Reply-To: $replyTo" : null,
            'Subject: ' . ca_encode_header($subject),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ])) . "\r\n\r\n" . rtrim(chunk_split(base64_encode($body), 76, "\r\n"));
        $error = ca_smtp_send($smtp, $from, $to, $message);
        if ($error !== null) {
            error_log("form.php SMTP failed: $error");
        }
        return $error === null;
    }

    $headers = implode("\r\n", array_filter([
        "From: $fromHeader",
        $replyTo !== '' ? "Reply-To: $replyTo" : null,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ]));
    return mail(implode(', ', $to), ca_encode_header($subject), $body, $headers, '-f' . $from);
}
