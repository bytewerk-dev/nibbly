<?php
// Fixture: a second template (one process can render only one) that also raises a PHP warning.
$pageTitle = 'Second';
$pageDescription = '';
$currentLang = 'en';
$currentPage = 'scan-second';
$contentPage = 'en_scan-second';
if (!isset($basePath)) $basePath = '../';
include '../includes/header.php';
include '../includes/content-loader.php';
echo $undefinedFixtureVariable;
?>
<main><h1><?php echo editableText($contentPage, 'title', 'Second'); ?></h1></main>
<?php include '../includes/footer.php'; ?>
