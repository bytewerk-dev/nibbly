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
        // Comments and guestbook (includes/comments.php); {question} = the arithmetic question
        'comments_title' => ['en' => 'Comments', 'de' => 'Kommentare', 'es' => 'Comentarios'],
        'comments_guestbook_title' => ['en' => 'Entries', 'de' => 'Einträge', 'es' => 'Entradas'],
        'comments_form_title' => ['en' => 'Write a comment', 'de' => 'Kommentar schreiben', 'es' => 'Escribir un comentario'],
        'comments_guestbook_form_title' => ['en' => 'Write an entry', 'de' => 'Eintrag schreiben', 'es' => 'Escribir una entrada'],
        'comments_empty' => ['en' => 'No comments yet.', 'de' => 'Noch keine Kommentare.', 'es' => 'Aún no hay comentarios.'],
        'comments_closed' => ['en' => 'Comments are closed.', 'de' => 'Kommentare sind geschlossen.', 'es' => 'Los comentarios están cerrados.'],
        'comments_reply' => ['en' => 'Reply', 'de' => 'Antworten', 'es' => 'Responder'],
        'comments_replying_to' => ['en' => 'Reply to', 'de' => 'Antwort an', 'es' => 'Respuesta a'],
        'comments_cancel_reply' => ['en' => 'Cancel reply', 'de' => 'Antwort abbrechen', 'es' => 'Cancelar respuesta'],
        'comments_owner' => ['en' => 'Author', 'de' => 'Autor', 'es' => 'Autor'],
        'comments_anonymous' => ['en' => 'Anonymous', 'de' => 'Anonym', 'es' => 'Anónimo'],
        'comments_name' => ['en' => 'Name', 'de' => 'Name', 'es' => 'Nombre'],
        'comments_email' => ['en' => 'E-mail', 'de' => 'E-Mail', 'es' => 'Correo electrónico'],
        'comments_email_hint' => ['en' => 'Is never published.', 'de' => 'Wird nicht veröffentlicht.', 'es' => 'No se publica.'],
        'comments_comment' => ['en' => 'Comment', 'de' => 'Kommentar', 'es' => 'Comentario'],
        'comments_entry' => ['en' => 'Entry', 'de' => 'Eintrag', 'es' => 'Entrada'],
        'comments_question' => ['en' => 'Spam check: what is {question}?', 'de' => 'Spamschutz: Was ergibt {question}?', 'es' => 'Control de spam: ¿cuánto es {question}?'],
        'comments_privacy' => ['en' => 'Name and text are published, the e-mail address is stored only for this entry.', 'de' => 'Name und Text werden veröffentlicht, die E-Mail-Adresse wird nur zu diesem Eintrag gespeichert.', 'es' => 'El nombre y el texto se publican; el correo solo se guarda para esta entrada.'],
        'comments_privacy_moderated' => ['en' => 'Entries appear after approval. Name and text are published, the e-mail address is stored only for this entry and never shown.', 'de' => 'Einträge erscheinen nach Freigabe. Name und Text werden veröffentlicht, die E-Mail-Adresse wird nur zu diesem Eintrag gespeichert und nie angezeigt.', 'es' => 'Las entradas aparecen tras su aprobación. El nombre y el texto se publican; el correo solo se guarda para esta entrada.'],
        'comments_send' => ['en' => 'Submit', 'de' => 'Absenden', 'es' => 'Enviar'],
        'comments_thanks_pending' => ['en' => 'Thank you! Your entry will appear after approval.', 'de' => 'Danke! Dein Eintrag erscheint nach der Freigabe.', 'es' => '¡Gracias! Tu entrada aparecerá tras su aprobación.'],
        'comments_thanks_published' => ['en' => 'Thank you! Your entry has been published.', 'de' => 'Danke! Dein Eintrag wurde veröffentlicht.', 'es' => '¡Gracias! Tu entrada se ha publicado.'],
        'comments_too_fast' => ['en' => 'That was quick. Please wait a moment and try again.', 'de' => 'Das ging zu schnell. Bitte einen Moment warten und noch einmal absenden.', 'es' => 'Demasiado rápido. Espera un momento e inténtalo de nuevo.'],
        'comments_wrong_answer' => ['en' => 'The answer to the spam check is not right.', 'de' => 'Die Antwort auf die Rechenfrage stimmt nicht.', 'es' => 'La respuesta al control de spam no es correcta.'],
        'comments_too_long' => ['en' => 'The text is too long.', 'de' => 'Der Text ist zu lang.', 'es' => 'El texto es demasiado largo.'],
        'comments_duplicate' => ['en' => 'This entry already exists.', 'de' => 'Diesen Eintrag gibt es schon.', 'es' => 'Esta entrada ya existe.'],
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
        // News lists, archive pages and pagination (includes/news-helper.php, includes/news-archive.php)
        'news_back' => [
            'en' => '&larr; Back to News',
            'de' => '&larr; Zurück zu Neuigkeiten',
            'es' => '&larr; Volver a noticias',
            'fr' => '&larr; Retour aux actualités',
            'it' => '&larr; Torna alle news',
            'pt' => '&larr; Voltar às notícias',
            'tr' => '&larr; Haberlere dön',
            'cs' => '&larr; Zpět na novinky',
            'pl' => '&larr; Wróć do aktualności',
        ],
        'news_archive_all' => [
            'en' => 'All posts',
            'de' => 'Alle Beiträge',
            'es' => 'Todas las publicaciones',
        ],
        'news_archive_tag' => [
            'en' => 'Topic: {tag}',
            'de' => 'Thema: {tag}',
            'es' => 'Tema: {tag}',
        ],
        'news_archive_year' => [
            'en' => 'Posts from {year}',
            'de' => 'Beiträge aus {year}',
            'es' => 'Publicaciones de {year}',
        ],
        'news_archive_decade' => [
            'en' => 'Posts from the {decade}s',
            'de' => 'Beiträge aus den {decade}er-Jahren',
            'es' => 'Publicaciones de los años {decade}',
        ],
        'news_categories' => [
            'en' => 'Categories',
            'de' => 'Rubriken',
            'es' => 'Categorías',
        ],
        'news_tags' => [
            'en' => 'Topics',
            'de' => 'Themen',
            'es' => 'Temas',
        ],
        'pagination_label' => [
            'en' => 'Pagination',
            'de' => 'Seitennavigation',
            'es' => 'Paginación',
        ],
        'pagination_prev' => [
            'en' => 'Previous',
            'de' => 'Zurück',
            'es' => 'Anterior',
        ],
        'pagination_next' => [
            'en' => 'Next',
            'de' => 'Weiter',
            'es' => 'Siguiente',
        ],
        'pagination_page' => [
            'en' => 'Page {page}',
            'de' => 'Seite {page}',
            'es' => 'Página {page}',
        ],
        'pagination_summary' => [
            'en' => 'Page {page} of {pages}',
            'de' => 'Seite {page} von {pages}',
            'es' => 'Página {page} de {pages}',
        ],
        // Search (includes/search-helper.php, includes/search.php)
        'search_label' => [
            'en' => 'Search',
            'de' => 'Suche',
            'es' => 'Buscar',
        ],
        'search_placeholder' => [
            'en' => 'Search the site…',
            'de' => 'Website durchsuchen…',
            'es' => 'Buscar en el sitio…',
        ],
        'search_button' => [
            'en' => 'Search',
            'de' => 'Suchen',
            'es' => 'Buscar',
        ],
        'search_title' => [
            'en' => 'Search',
            'de' => 'Suche',
            'es' => 'Búsqueda',
        ],
        'search_results_for' => [
            'en' => 'Results for “{query}”',
            'de' => 'Ergebnisse für „{query}“',
            'es' => 'Resultados para «{query}»',
        ],
        'search_count' => [
            'en' => '{count} results',
            'de' => '{count} Treffer',
            'es' => '{count} resultados',
        ],
        'search_count_one' => [
            'en' => '1 result',
            'de' => '1 Treffer',
            'es' => '1 resultado',
        ],
        'search_none' => [
            'en' => 'Nothing found. Try another word or a shorter one.',
            'de' => 'Nichts gefunden. Versuch es mit einem anderen oder kürzeren Begriff.',
            'es' => 'No se encontró nada. Prueba con otra palabra o una más corta.',
        ],
        'search_too_short' => [
            'en' => 'Please enter at least {min} characters.',
            'de' => 'Bitte gib mindestens {min} Zeichen ein.',
            'es' => 'Introduce al menos {min} caracteres.',
        ],
        'search_intro' => [
            'en' => 'Search posts and pages.',
            'de' => 'Beiträge und Seiten durchsuchen.',
            'es' => 'Buscar en publicaciones y páginas.',
        ],
        'search_type_page' => [
            'en' => 'Page',
            'de' => 'Seite',
            'es' => 'Página',
        ],
        'search_type_post' => [
            'en' => 'Post',
            'de' => 'Beitrag',
            'es' => 'Publicación',
        ],
        // Timeline of posts (renderNewsTimeline())
        'timeline_jump_label' => [
            'en' => 'Jump to',
            'de' => 'Springe zu',
            'es' => 'Ir a',
        ],
        'timeline_read_more' => [
            'en' => 'Read the post',
            'de' => 'Zum Beitrag',
            'es' => 'Leer la publicación',
        ],
        'timeline_empty' => [
            'en' => 'No entries yet.',
            'de' => 'Noch keine Einträge.',
            'es' => 'Todavía no hay entradas.',
        ],
        'feed_title' => [
            'en' => 'Posts (RSS)',
            'de' => 'Beiträge (RSS)',
            'es' => 'Publicaciones (RSS)',
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
