<?php
/**
 * Custom layout page — uses editable fields and render components.
 *
 * This template demonstrates how to build a page with a custom HTML structure
 * while keeping content editable via the inline editor and admin dashboard.
 *
 * Usage: Copy to {lang}/your-page.php and customize the layout.
 * Create matching content at content/pages/{lang}_your-page.json.
 */
$pageTitle = 'Custom Page';
$pageDescription = '';
$currentLang = 'en';
$currentPage = 'your-page';
$contentPage = 'en_your-page';
$basePath = '../';

include '../includes/header.php';
include '../includes/content-loader.php';

$_p = $contentPage;
?>

    <main class="main-content">
        <div class="content-inner">

            <!-- Hero section with editable text -->
            <section class="hero">
                <h1><?php echo editableText($_p, 'hero.title', 'Welcome'); ?></h1>
                <p><?php echo editableText($_p, 'hero.subtitle', 'Your tagline here.'); ?></p>
                <a href="<?php echo editableText($_p, 'hero.cta.href', '#'); ?>" class="btn btn-primary">
                    <?php echo editableText($_p, 'hero.cta.text', 'Get Started'); ?>
                </a>
            </section>

            <!-- Editable image -->
            <figure>
                <img <?php echo editableImage($_p, 'hero.image', 'https://placehold.co/800x400'); ?>
                     alt="<?php echo editableText($_p, 'hero.image_alt', 'Hero image'); ?>">
            </figure>

            <!-- Feature grid component -->
            <section>
                <h2><?php echo editableText($_p, 'features.heading', 'Features'); ?></h2>
                <?php echo renderFeatureGrid($_p); ?>
            </section>

            <!-- FAQ accordion component -->
            <section>
                <h2><?php echo editableText($_p, 'faq.heading', 'FAQ'); ?></h2>
                <?php echo renderFaqAccordion($_p); ?>
            </section>

            <!-- Pricing table component -->
            <section>
                <h2><?php echo editableText($_p, 'pricing.heading', 'Pricing'); ?></h2>
                <?php echo renderPricingTable($_p); ?>
            </section>

        </div>
    </main>

<?php include '../includes/footer.php'; ?>
