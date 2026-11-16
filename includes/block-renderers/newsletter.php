<?php
/** Newsletter sign-up form (includes/newsletter.php); empty for visitors while the module is off. */
require_once __DIR__ . '/../newsletter.php';
$form = nibblyNewsletterForm([
    'names' => ($section['names'] ?? 'no') === 'yes',
    'button' => (string)($section['button'] ?? ''),
    'lists' => array_filter([(string)($section['list'] ?? '')]),
]);
if ($form === '') return $editable ? '<p class="nibbly-newsletter-off">' . htmlspecialchars(t('newsletter.block_off')) . '</p>' : '';
return '<div class="nibbly-newsletter">' . $form . '</div>';
