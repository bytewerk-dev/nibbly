<?php
/**
 * 410 Gone Error Page — wrapper around the generic error renderer.
 *
 * For permanently removed content where 301-redirecting to an unrelated
 * page would be misleading and a default 404 understates the finality.
 */
$errorCode = 410;
include __DIR__ . '/error.php';
