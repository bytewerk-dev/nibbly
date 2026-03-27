<?php
/**
 * Pricing Contact Form
 * Contact form with package selection for the pricing page.
 * Expects $currentLang, $basePath, and optionally $_selectedPackage to be set.
 */

$_cfLang = $currentLang ?? 'en';
$_cfBasePath = $basePath ?? '';
$_selectedPackage = $_selectedPackage ?? '';

$_cfStrings = [
    'de' => [
        'heading'     => 'Projekt anfragen',
        'subheading'  => 'Erzähl mir von deinem Vorhaben — ich melde mich innerhalb von 24 Stunden.',
        'name'        => 'Name',
        'email'       => 'E-Mail',
        'phone'       => 'Telefon',
        'package'     => 'Paket',
        'package_opt' => [
            ''               => 'Bitte wählen…',
            'Starter Setup'  => 'Starter Setup — 1.990 €',
            'Full Service'   => 'Full Service — 4.990 €',
            'Care Plan'      => 'Care Plan — 99 €/Monat',
            'Custom'         => 'Individuelles Projekt',
        ],
        'website_url' => 'Bestehende Website (falls vorhanden)',
        'languages'   => 'Gewünschte Sprachen',
        'lang_opts'   => ['1 Sprache', '2 Sprachen (inkludiert)', '3+ Sprachen (+ 299 € pro Sprache)'],
        'deadline'    => 'Wunschtermin',
        'message'     => 'Erzähl mir von deinem Projekt',
        'send'        => 'Anfrage senden',
        'sending'     => 'Wird gesendet…',
        'success'     => 'Vielen Dank! Ich melde mich in Kürze.',
        'error'       => 'Fehler beim Senden. Bitte versuche es später erneut.',
    ],
    'en' => [
        'heading'     => 'Request a project',
        'subheading'  => 'Tell me about your plans — I\'ll get back to you within 24 hours.',
        'name'        => 'Name',
        'email'       => 'Email',
        'phone'       => 'Phone',
        'package'     => 'Package',
        'package_opt' => [
            ''               => 'Please select…',
            'Starter Setup'  => 'Starter Setup — € 1,990',
            'Full Service'   => 'Full Service — € 4,990',
            'Care Plan'      => 'Care Plan — € 99/mo',
            'Custom'         => 'Custom project',
        ],
        'website_url' => 'Existing website (if any)',
        'languages'   => 'Languages needed',
        'lang_opts'   => ['1 language', '2 languages (included)', '3+ languages (+ € 299 per language)'],
        'deadline'    => 'Preferred timeline',
        'message'     => 'Tell me about your project',
        'send'        => 'Send request',
        'sending'     => 'Sending…',
        'success'     => 'Thank you! I\'ll be in touch shortly.',
        'error'       => 'Error sending. Please try again later.',
    ],
    'es' => [
        'heading'     => 'Solicitar proyecto',
        'subheading'  => 'Cuéntame sobre tus planes — te responderé en 24 horas.',
        'name'        => 'Nombre',
        'email'       => 'Correo electrónico',
        'phone'       => 'Teléfono',
        'package'     => 'Paquete',
        'package_opt' => [
            ''               => 'Por favor selecciona…',
            'Starter Setup'  => 'Starter Setup — 1.990 €',
            'Full Service'   => 'Full Service — 4.990 €',
            'Care Plan'      => 'Care Plan — 99 €/mes',
            'Custom'         => 'Proyecto personalizado',
        ],
        'website_url' => 'Sitio web existente (si lo hay)',
        'languages'   => 'Idiomas necesarios',
        'lang_opts'   => ['1 idioma', '2 idiomas (incluido)', '3+ idiomas (+ 299 € por idioma)'],
        'deadline'    => 'Fecha deseada',
        'message'     => 'Cuéntame sobre tu proyecto',
        'send'        => 'Enviar solicitud',
        'sending'     => 'Enviando…',
        'success'     => '¡Gracias! Me pondré en contacto pronto.',
        'error'       => 'Error al enviar. Inténtalo de nuevo más tarde.',
    ],
];

$_cf = $_cfStrings[$_cfLang] ?? $_cfStrings['en'];
?>

<section class="pricing-contact" id="pricing-contact">
    <div class="pricing-container">
        <div class="pricing-contact__box">
            <h2 class="pricing-contact__heading"><?php echo $_cf['heading']; ?></h2>
            <p class="pricing-contact__subheading"><?php echo $_cf['subheading']; ?></p>

            <form id="contactForm" class="contact-form pricing-contact-form" action="<?php echo $_cfBasePath; ?>api/contact.php" method="post">
                <input type="hidden" name="lang" value="<?php echo htmlspecialchars($_cfLang); ?>">
                <input type="hidden" name="occasion" id="pricingOccasion" value="<?php echo htmlspecialchars($_selectedPackage ? 'Pricing: ' . $_selectedPackage : ''); ?>">
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

                <div class="form-row">
                    <div class="form-group">
                        <label for="contact-phone"><?php echo $_cf['phone']; ?></label>
                        <input type="tel" id="contact-phone" name="phone">
                    </div>
                    <div class="form-group">
                        <label for="pricing-package"><?php echo $_cf['package']; ?> *</label>
                        <select id="pricing-package" name="package" required>
                            <?php foreach ($_cf['package_opt'] as $val => $label): ?>
                            <option value="<?php echo htmlspecialchars($val); ?>"<?php echo $val === $_selectedPackage ? ' selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="pricing-url"><?php echo $_cf['website_url']; ?></label>
                        <input type="url" id="pricing-url" name="website_url" placeholder="https://...">
                    </div>
                    <div class="form-group">
                        <label for="pricing-languages"><?php echo $_cf['languages']; ?></label>
                        <select id="pricing-languages" name="languages">
                            <?php foreach ($_cf['lang_opts'] as $opt): ?>
                            <option value="<?php echo htmlspecialchars($opt); ?>"><?php echo htmlspecialchars($opt); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label for="contact-message"><?php echo $_cf['message']; ?> *</label>
                    <textarea id="contact-message" name="message" rows="4" required></textarea>
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
        </div>
    </div>
</section>

<script>
(function() {
    var packageSelect = document.getElementById('pricing-package');
    var occasionField = document.getElementById('pricingOccasion');
    var contactSection = document.getElementById('pricing-contact');

    var langSelect = document.getElementById('pricing-languages');

    // Disable language select for Care Plan / Custom
    function toggleLangSelect() {
        var val = packageSelect ? packageSelect.value : '';
        var disable = (val === 'Care Plan' || val === 'Custom');
        if (langSelect) {
            langSelect.disabled = disable;
            if (disable) langSelect.selectedIndex = 0;
            langSelect.closest('.form-group').style.opacity = disable ? '0.4' : '1';
        }
    }

    // Sync package dropdown → hidden occasion field + toggle languages
    if (packageSelect && occasionField) {
        packageSelect.addEventListener('change', function() {
            occasionField.value = this.value ? 'Pricing: ' + this.value : '';
            toggleLangSelect();
        });
        toggleLangSelect();
    }

    // Pricing card CTA buttons → scroll to form and preselect package
    document.querySelectorAll('.pricing-card .pricing-card__action a[href="#"]').forEach(function(btn) {
        var card = btn.closest('.pricing-card');
        if (!card) return;
        var nameEl = card.querySelector('.pricing-card__name');
        if (!nameEl) return;
        var planName = nameEl.textContent.trim();

        btn.addEventListener('click', function(e) {
            e.preventDefault();
            // Find matching option by plan name
            for (var i = 0; i < packageSelect.options.length; i++) {
                if (packageSelect.options[i].value === planName) {
                    packageSelect.selectedIndex = i;
                    occasionField.value = 'Pricing: ' + planName;
                    break;
                }
            }
            toggleLangSelect();
            contactSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    });
})();
</script>
