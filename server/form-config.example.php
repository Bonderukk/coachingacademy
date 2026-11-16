<?php
// Settings for form.php and the order administration. Copy this file to form-config.php and fill it in.
// form-config.php is not in git: it holds the recipients, passwords and the encryption key.
// On the server it sits next to form.php (or, better, one folder above the web folder).
return [
    // Who receives the orders and messages: one address or a list.
    'mail_to' => 'dalibor@coachingacademy.sk',
    // 'mail_to' => ['dalibor@coachingacademy.sk', 'someone@example.com'],

    // Send through a real mailbox (PHP mail() from the hosting is silently dropped by Gmail and Websupport).
    // Create the mailbox in Webadmin, then fill in its address and password:
    // 'smtp' => ['host' => 'smtp.m1.websupport.sk', 'port' => 465, 'user' => 'web@coachacademy.sk', 'pass' => 'HESLO'],

    // Database for book orders (Webadmin → webhosting → Databázy). Without it orders are only e-mailed.
    // 'db' => ['host' => 'db.r6.websupport.sk', 'port' => 3306, 'name' => 'NAZOV', 'user' => 'POUZIVATEL', 'pass' => 'HESLO'],

    // Only while the new site is not live on coachingacademy.sk: read the price list from the preview.
    // 'catalog_url' => 'https://bonderukk.github.io/coachingacademy/catalog.json',

    // Personal data in an order is erased this many months after it was shipped / cancelled (defaults: 36 and 6).
    // 'retention_months' => 36,
    // 'retention_cancelled_months' => 6,

    // The encryption key and the administrators are added below by:  php server/tools/setup.php
];
