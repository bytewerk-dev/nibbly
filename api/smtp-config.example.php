<?php
/**
 * SMTP Configuration for email sending
 * Copy this file to smtp-config.php and adjust the values.
 *
 * IMPORTANT: This file contains credentials and should NOT be publicly accessible!
 */

define('SMTP_HOST', 'mail.example.com');
define('SMTP_PORT', 587);  // TLS Port (alternative: 465 for SSL)
define('SMTP_USERNAME', 'your@email.com');
define('SMTP_PASSWORD', 'your-password-here');
define('SMTP_FROM_EMAIL', 'your@email.com');
define('SMTP_FROM_NAME', 'My Website');
define('SMTP_ENCRYPTION', 'tls');  // 'tls' or 'ssl'
