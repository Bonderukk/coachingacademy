<?php
// Settings for form.php. Copy this file to form-config.php on the server, next to form.php.
// form-config.php is not in git, because it holds the recipients and the SMTP password.
return [
    // Who receives the orders and messages: one address or a list.
    'mail_to' => 'dalibor@coachingacademy.sk',
    // 'mail_to' => ['dalibor@coachingacademy.sk', 'someone@example.com'],

    // Recommended: send through a real mailbox (more reliable than PHP mail()).
    // Create the mailbox in Webadmin, then fill in its address and password:
    // 'smtp' => ['host' => 'smtp.m1.websupport.sk', 'port' => 465, 'user' => 'web@coachacademy.sk', 'pass' => 'HESLO'],
];
