<?php
// Fixture: a custom layout with every kind of editable field (tests/agent-capabilities-smoke.php).
$pageTitle = 'Scan fixture';
$pageDescription = '';
$currentLang = 'en';
$currentPage = 'scan-custom';
$contentPage = 'en_scan-custom';
if (!isset($basePath)) $basePath = '../';

include '../includes/header.php';
include '../includes/content-loader.php';

@file_put_contents(dirname(__DIR__) . '/render-count-custom.txt', 'x', FILE_APPEND); // proves how often the template really ran
$_p = $contentPage;
$featureDefaults = ['title' => 'New feature', 'text' => '', 'image' => ['src' => '', 'alt' => ''], 'icon' => 'star'];
$featureSchema = ['label' => 'Feature', 'fields' => [
    ['key' => 'title', 'type' => 'text'], ['key' => 'text', 'type' => 'textarea'],
    ['key' => 'image', 'type' => 'image'], ['key' => 'icon', 'type' => 'icon'],
]];
?>
<main class="main-content">
    <section class="hero">
        <h1><?php echo editableText($_p, 'hero.title', 'Fallback title'); ?></h1>
        <p><?php echo editableText($_p, 'hero.tagline', 'Default tagline'); ?></p>
        <div><?php echo editableHtml($_p, 'hero.body', '<p>Default</p>'); ?></div>
        <?php echo editableLink($_p, 'hero.cta', 'Go', '#', 'btn'); ?>
        <?php echo editableImage($_p, 'hero.image', '/assets/images/placeholder-image.webp', 'Hero alt', 'hero__img'); ?>
        <?php echo editableImageSplit($_p, 'hero.photo', 'hero.photoAlt', '/assets/images/placeholder-image.webp', 'Photo alt'); ?>
        <?php echo editableImage($_p, 'gallery.cover', '/assets/images/placeholder-image.webp', 'Cover'); ?>
        <?php echo editableIcon($_p, 'hero.icon', 'star'); ?>
        <?php echo editableText($_p, 'hero.note', 'Note'); ?>
        <?php echo editableText($_p, 'hero.type', 'banner'); ?>
        <?php echo editableText($_p, 'seo.title', 'SEO title'); ?>
        <?php echo editableText($_p, 'contact.apiKey', 'key'); ?>
        <?php echo editableText($_p, 'contact.email', 'mail@example.org'); ?>
        <div data-nb-bind="image" data-nb-bind-page="<?php echo $_p; ?>" data-nb-bind-field="hero.mirror"></div>
        <?php echo editableText('sidebar', 'promo.title', 'Promo'); ?>
    </section>

    <section class="features">
        <h2><?php echo editableText($_p, 'features.heading', 'Features'); ?></h2>
        <div class="grid"<?php echo editableListAttrs($_p, 'features.items', $featureDefaults); ?>>
            <?php foreach (editableListItems($_p, 'features.items') as $i => $item): ?>
                <article<?php echo editableListGroupItemAttrs($_p, 'features.items', $i, $featureSchema, $featureDefaults, 'Feature'); ?>>
                    <?php echo editableIcon($_p, "features.items.$i.icon", 'star'); ?>
                    <h3><?php echo editableText($_p, "features.items.$i.title", ''); ?></h3>
                    <?php echo editableImage($_p, "features.items.$i.image", '', ''); ?>
                    <p><?php echo editableText($_p, "features.items.$i.text", ''); ?></p>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="about">
        <?php echo editableTextList($_p, 'about.paragraphs'); ?>
    </section>

    <section class="faq">
        <div<?php echo editableListAttrs($_p, 'faq.items', ['question' => 'Question?', 'answer' => '', 'link' => ['text' => '', 'href' => '']]); ?>></div>
    </section>
</main>
<?php include '../includes/footer.php'; ?>
