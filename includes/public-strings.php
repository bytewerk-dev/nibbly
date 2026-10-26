<?php
/**
 * Visitor-facing strings of the core templates, in the language of the page.
 *
 * t() (admin/lang/i18n.php) follows the admin language; these strings follow the
 * page being viewed instead, like the event labels in formatEventDate(). English,
 * German and Spanish match the starter content; other languages fall back to English.
 */

/** Page language: the given code, else $currentLang of the request, else the primary language. */
function nibblyPublicLang(?string $lang = null): string {
    $lang = $lang ?? ($GLOBALS['currentLang'] ?? null) ?? (defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en');
    return strtolower((string)$lang);
}

/** A visitor-facing string in the page language (English fallback, then the key). */
function nibblyPublicString(string $key, ?string $lang = null): string {
    static $strings = [
        'skip_to_content' => [
            'en' => 'Skip to main content',
            'de' => 'Zum Inhalt springen',
            'es' => 'Saltar al contenido principal',
        ],
        'form_requires_js' => [
            'en' => 'This form requires JavaScript.',
            'de' => 'Dieses Formular benötigt JavaScript.',
            'es' => 'Este formulario requiere JavaScript.',
        ],
        // Form messages (includes/forms.php, api/form-submit.php); {field} = field label
        'form_send' => [
            'en' => 'Send',
            'de' => 'Absenden',
            'es' => 'Enviar',
        ],
        'form_sending' => [
            'en' => 'Sending…',
            'de' => 'Wird gesendet…',
            'es' => 'Enviando…',
        ],
        'form_send_error' => [
            'en' => 'Could not send the form. Please try again later.',
            'de' => 'Fehler beim Senden. Bitte versuch es später noch einmal.',
            'es' => 'No se pudo enviar el formulario. Inténtalo de nuevo más tarde.',
        ],
        'form_field_required' => [
            'en' => '{field} is required',
            'de' => '{field} ist erforderlich',
            'es' => '{field} es obligatorio',
        ],
        'form_email_invalid' => [
            'en' => 'Valid email is required',
            'de' => 'Gültige E-Mail ist erforderlich',
            'es' => 'Se requiere un correo electrónico válido',
        ],
        'form_not_found' => [
            'en' => 'Form not found.',
            'de' => 'Formular nicht gefunden.',
            'es' => 'Formulario no encontrado.',
        ],
        'form_too_many' => [
            'en' => 'Too many attempts. Please try again later.',
            'de' => 'Zu viele Versuche. Bitte versuch es später noch einmal.',
            'es' => 'Demasiados intentos. Inténtalo de nuevo más tarde.',
        ],
        'form_expired' => [
            'en' => 'The form expired or was submitted too quickly. Please try again.',
            'de' => 'Das Formular ist abgelaufen oder wurde zu schnell gesendet. Bitte versuch es noch einmal.',
            'es' => 'El formulario ha caducado o se envió demasiado rápido. Inténtalo de nuevo.',
        ],
        'form_check_input' => [
            'en' => 'The message could not be sent. Please check your input.',
            'de' => 'Die Nachricht konnte nicht gesendet werden. Bitte prüf deine Eingaben.',
            'es' => 'No se pudo enviar el mensaje. Revisa tus datos.',
        ],
        'form_save_failed' => [
            'en' => 'The message could not be saved. Please try again later.',
            'de' => 'Die Nachricht konnte nicht gespeichert werden. Bitte versuch es später noch einmal.',
            'es' => 'No se pudo guardar el mensaje. Inténtalo de nuevo más tarde.',
        ],
        'form_send_failed' => [
            'en' => 'The message could not be sent. Please try again later.',
            'de' => 'Die Nachricht konnte nicht gesendet werden. Bitte versuch es später noch einmal.',
            'es' => 'No se pudo enviar el mensaje. Inténtalo de nuevo más tarde.',
        ],
        'form_load_error' => [
            'en' => 'This form could not be loaded. Please try again later.',
            'de' => 'Das Formular konnte nicht geladen werden. Bitte versuch es später noch einmal.',
            'es' => 'No se pudo cargar el formulario. Inténtalo de nuevo más tarde.',
        ],
        // Event and person pages (includes/event-page.php, includes/person-page.php)
        'events_back' => [
            'en' => 'All events',
            'de' => 'Alle Termine',
            'es' => 'Todos los eventos',
        ],
        'people_back' => [
            'en' => 'Back',
            'de' => 'Zurück',
            'es' => 'Volver',
        ],
        'not_found' => [
            'en' => 'Not found',
            'de' => 'Nicht gefunden',
            'es' => 'No encontrado',
        ],
        'event_admission' => [
            'en' => 'Admission',
            'de' => 'Eintritt',
            'es' => 'Entrada',
        ],
        'event_website' => [
            'en' => 'Visit website',
            'de' => 'Zur Website',
            'es' => 'Visitar web',
        ],
        'more_details' => [
            'en' => 'Details',
            'de' => 'Details',
            'es' => 'Detalles',
        ],
        'timeline_upcoming' => [
            'en' => 'upcoming',
            'de' => 'geplant',
            'es' => 'próximamente',
        ],
    ];
    $lang = nibblyPublicLang($lang);
    return $strings[$key][$lang] ?? $strings[$key]['en'] ?? $key;
}

/**
 * A news date in the page language: "Oct 23, 2026" / "23. Oktober 2026" / "23 de octubre de 2026".
 * $long spells the English month out ("October 23, 2026"), as on the post page.
 */
function nibblyFormatNewsDate(DateTimeInterface $date, ?string $lang = null, bool $long = false): string {
    $lang = nibblyPublicLang($lang);
    if (($lang === 'de' || $lang === 'es') && function_exists('formatEventDateShort')) {
        return formatEventDateShort($date->format('Y-m-d'), $lang);
    }
    return $date->format($long ? 'F j, Y' : 'M j, Y');
}
