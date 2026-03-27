<?php
/**
 * Contact Form Partial
 * Include this in any page to render a contact form.
 * Expects $currentLang and $basePath to be set.
 */

$_cfLang = $currentLang ?? 'en';
$_cfBasePath = $basePath ?? '';

// Translations
$_cfStrings = [
    'de' => [
        'heading'     => 'Schreib uns ein paar Bytes',
        'name'        => 'Name',
        'email'       => 'E-Mail',
        'phone'       => 'Telefon',
        'message'     => 'Nachricht',
        'send'        => 'Nachricht senden',
        'sending'     => 'Wird gesendet…',
        'success'     => 'Vielen Dank für Ihre Nachricht!',
        'error'       => 'Fehler beim Senden. Bitte versuchen Sie es später erneut.',
        'occasion'    => 'Kontaktanfrage',
    ],
    'en' => [
        'heading'     => 'Drop us a few bytes',
        'name'        => 'Name',
        'email'       => 'Email',
        'phone'       => 'Phone',
        'message'     => 'Message',
        'send'        => 'Send Message',
        'sending'     => 'Sending…',
        'success'     => 'Thank you for your message!',
        'error'       => 'Error sending. Please try again later.',
        'occasion'    => 'Contact inquiry',
    ],
    'es' => [
        'heading'     => 'Envíanos unos bytes',
        'name'        => 'Nombre',
        'email'       => 'Correo electrónico',
        'phone'       => 'Teléfono',
        'message'     => 'Mensaje',
        'send'        => 'Enviar mensaje',
        'sending'     => 'Enviando…',
        'success'     => '¡Gracias por su mensaje!',
        'error'       => 'Error al enviar. Inténtelo de nuevo más tarde.',
        'occasion'    => 'Consulta de contacto',
    ],
];

$_cf = $_cfStrings[$_cfLang] ?? $_cfStrings['en'];
?>

<section class="contact-form-section">
    <h2><?php echo $_cf['heading']; ?></h2>

    <form id="contactForm" class="contact-form" action="<?php echo $_cfBasePath; ?>api/contact.php" method="post">
        <input type="hidden" name="lang" value="<?php echo htmlspecialchars($_cfLang); ?>">
        <input type="hidden" name="occasion" value="<?php echo htmlspecialchars($_cf['occasion']); ?>">
        <!-- Honeypot -->
        <div style="position:absolute;left:-9999px" aria-hidden="true">
            <input type="text" name="website" tabindex="-1" autocomplete="off">
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="contact-name"><?php echo $_cf['name']; ?> *</label>
                <input type="text" id="contact-name" name="name" required>
            </div>
            <div class="form-group">
                <label for="contact-email"><?php echo $_cf['email']; ?> *</label>
                <input type="email" id="contact-email" name="email" required>
            </div>
        </div>

        <div class="form-group">
            <label for="contact-phone"><?php echo $_cf['phone']; ?></label>
            <input type="tel" id="contact-phone" name="phone">
        </div>

        <div class="form-group">
            <label for="contact-message"><?php echo $_cf['message']; ?> *</label>
            <textarea id="contact-message" name="message" rows="5" required></textarea>
        </div>

        <div class="form-actions">
            <button type="submit" id="contactSubmit" class="btn btn-gradient">
                <span class="btn-text"><?php echo $_cf['send']; ?></span>
                <span class="btn-loading" style="display:none"><?php echo $_cf['sending']; ?></span>
            </button>
        </div>

        <div id="formFeedback" class="form-feedback"
             data-success="<?php echo htmlspecialchars($_cf['success']); ?>"
             data-error="<?php echo htmlspecialchars($_cf['error']); ?>"></div>
    </form>
</section>
