<?php
/**
 * Smoke tests for the nibbly frontend AI Assistant server contracts.
 *
 * Run from the repository root:
 * php tests/copilot-smoke.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);

if (!defined('SITE_NAME')) define('SITE_NAME', 'nibbly Test');
if (!defined('SITE_LANG_DEFAULT')) define('SITE_LANG_DEFAULT', 'en');
if (!defined('CONTENT_PATH')) define('CONTENT_PATH', $root . '/content/pages/');
if (!defined('CONTENT_BASE_PATH')) define('CONTENT_BASE_PATH', $root . '/content/pages/');
if (!defined('NIBBLY_AI_AUDIT_DIR')) define('NIBBLY_AI_AUDIT_DIR', $root . '/tmp/copilot-smoke-ai-audit');

$_SESSION = ['admin_role' => 'editor', 'admin_username' => 'smoke'];

require_once $root . '/includes/content-loader.php';
require_once $root . '/includes/ai/copilot-context.php';
require_once $root . '/includes/ai/ai-helper.php';

$createdFiles = [];
$createdDirs = [NIBBLY_AI_AUDIT_DIR];

function copilotSmokeAssert(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function copilotSmokeRejects(callable $callback, string $message): void {
    $rejected = false;
    try {
        $callback();
    } catch (Throwable $e) {
        $rejected = true;
    }
    copilotSmokeAssert($rejected, $message);
}

function copilotSmokeWriteFixture(string $path, string $content): void {
    global $createdFiles;
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create fixture directory: ' . $dir);
    }
    if (file_put_contents($path, $content) === false) {
        throw new RuntimeException('Could not write fixture: ' . $path);
    }
    $createdFiles[] = $path;
}

function copilotSmokeRemoveDir(string $dir): void {
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    if (!is_array($items)) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            copilotSmokeRemoveDir($path);
        } elseif (is_file($path)) {
            unlink($path);
        }
    }
    rmdir($dir);
}

try {
    $page = 'en_zz-copilot-smoke';
    $pageFile = CONTENT_PATH . $page . '.json';
    copilotSmokeWriteFixture($pageFile, json_encode([
        'title' => 'Old title',
        'description' => 'Old description',
        'description__hidden' => true,
        'body' => '<p>Old <strong>body</strong></p>',
        'cta' => ['text' => 'Old CTA', 'href' => '/old'],
        'seo' => ['robots' => 'index, follow'],
        'sections' => [
            [
                'id' => 'section_intro',
                'type' => 'text',
                'title' => 'Section heading',
                'content' => '<p>Section body</p>'
            ],
            [
                'id' => 'section_hero_image',
                'type' => 'image',
                'src' => '/assets/images/generated/zz-copilot-raster-test.png',
                'alt' => 'Section image',
                'hidden' => true
            ],
            [
                'id' => 'section_steps',
                'type' => 'list',
                'title' => 'Steps',
                'items' => [
                    ['text' => 'First step'],
                    ['text' => 'Second step'],
                ],
                'content' => ''
            ]
        ],
        'published' => true,
        'hero' => ['src' => '/assets/images/generated/zz-copilot-raster-test.png', 'alt' => 'Old alt'],
        'visibility' => ['passwordHash' => 'must-not-leak', 'token' => 'must-not-leak'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $imageDir = $root . '/assets/images/generated';
    $validPng = $imageDir . '/zz-copilot-raster-test.png';
    $validVariantPng = $imageDir . '/zz-copilot-raster-variant-test.png';
    $fakePng = $imageDir . '/zz-copilot-fake-test.png';
    $svg = $imageDir . '/zz-copilot-svg-test.svg';
    copilotSmokeWriteFixture($validPng, (string)base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII='));
    copilotSmokeWriteFixture($validVariantPng, (string)base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII='));
    copilotSmokeWriteFixture($fakePng, 'not a real png');
    copilotSmokeWriteFixture($svg, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

    $context = nibblyCopilotBuildContext($page, [
        'enabled' => true,
        'hasApiKey' => true,
        'apiKey' => 'sk-should-not-leak',
        'provider' => 'openai-compatible',
        'baseUrl' => 'https://provider.invalid/v1',
        'providerCredentials' => [
            'openai-compatible' => [
                'apiKey' => 'sk-provider-secret',
                'baseUrl' => 'https://provider.invalid/v1',
                'organization' => 'org-secret'
            ]
        ],
        'features' => ['backendAssistant' => true, 'seoTextGeneration' => true, 'imageGeneration' => true],
        'chatModel' => 'test-chat',
        'imageModel' => 'test-image'
    ]);
    $contextJson = json_encode($context, JSON_UNESCAPED_SLASHES);
    copilotSmokeAssert(is_string($contextJson), 'Context JSON encoding failed.');
    foreach (['sk-should-not-leak', 'sk-provider-secret', 'providerCredentials', 'apiKey', 'baseUrl', 'org-secret'] as $secretNeedle) {
        copilotSmokeAssert(strpos($contextJson, $secretNeedle) === false, 'Safe Copilot context leaked AI setting: ' . $secretNeedle);
    }

    copilotSmokeAssert(!empty($context['page']['exists']), 'Context did not find fixture page.');
    $sectionTextField = array_values(array_filter($context['page']['fields'] ?? [], fn($field) => ($field['path'] ?? '') === 'sections.0.content'))[0] ?? null;
    copilotSmokeAssert(is_array($sectionTextField), 'Section text field missing from Assistant manifest.');
    copilotSmokeAssert(strpos((string)($sectionTextField['label'] ?? ''), 'Sec. 1 text: Section heading - content') !== false, 'Section text field labels should include short section number, type, title, and concrete field name.');
    $sectionImageField = array_values(array_filter($context['page']['fields'] ?? [], fn($field) => ($field['path'] ?? '') === 'sections.1.src'))[0] ?? null;
    copilotSmokeAssert(is_array($sectionImageField), 'Section image field missing from Assistant manifest.');
    copilotSmokeAssert(strpos((string)($sectionImageField['label'] ?? ''), 'Sec. 2 image: Section image - src') !== false, 'Section image field labels should include short section number, type, preview text, and concrete field name.');
    $sectionListItemField = array_values(array_filter($context['page']['fields'] ?? [], fn($field) => ($field['path'] ?? '') === 'sections.2.items.1.text'))[0] ?? null;
    copilotSmokeAssert(is_array($sectionListItemField), 'Section list item field missing from Assistant manifest.');
    copilotSmokeAssert(strpos((string)($sectionListItemField['label'] ?? ''), 'Sec. 3 list: Steps - item 2 text') !== false, 'Section list item labels should include the item number.');
    copilotSmokeAssert(!empty($context['knowledgeBase']) && is_array($context['knowledgeBase']), 'Copilot knowledge base missing from safe context.');
    $knowledgeIds = array_map(fn($item) => $item['id'] ?? '', $context['knowledgeBase']);
    foreach (['overview', 'inline-editing', 'standard-pages', 'content-types', 'ai', 'security'] as $knowledgeId) {
        copilotSmokeAssert(in_array($knowledgeId, $knowledgeIds, true), 'Copilot knowledge base missing topic: ' . $knowledgeId);
    }
    $systemPrompt = nibblyCopilotSystemPrompt($context);
    copilotSmokeAssert(strpos($systemPrompt, 'knowledgeBase') !== false, 'Copilot system prompt does not include knowledge base guidance.');
    copilotSmokeAssert(strpos($systemPrompt, 'editableText()') !== false, 'Copilot system prompt lacks nibbly inline editing knowledge.');
    copilotSmokeAssert(strpos($systemPrompt, 'publishing AI-created drafts') !== false, 'Copilot system prompt does not mention implemented publish support.');
    copilotSmokeAssert(in_array('publish-created-content', $context['copilot']['supportedNow'] ?? [], true), 'Copilot runtime context does not expose implemented publish support.');
    copilotSmokeAssert(in_array('image-alt-suggestions', $context['copilot']['supportedNow'] ?? [], true), 'Copilot runtime context does not expose implemented image alt suggestions.');
    copilotSmokeAssert(!in_array('publish-flows', $context['copilot']['plannedActions'] ?? [], true), 'Implemented publish flows are still advertised as planned.');
    copilotSmokeAssert(!in_array('image-alt-suggestions', $context['copilot']['plannedActions'] ?? [], true), 'Implemented image alt suggestions are still advertised as planned.');
    $paths = array_map(fn($field) => $field['path'] ?? '', $context['page']['fields'] ?? []);
    copilotSmokeAssert(in_array('title', $paths, true), 'Editable title field missing from manifest.');
    copilotSmokeAssert(in_array('cta.text', $paths, true), 'Editable link text field missing from manifest.');
    copilotSmokeAssert(in_array('cta.href', $paths, true), 'Editable link href field missing from manifest.');
    copilotSmokeAssert(in_array('sections.0.content', $paths, true), 'Editable section content field missing from manifest.');
    copilotSmokeAssert(in_array('sections.1.src', $paths, true), 'Editable section image src field missing from manifest.');
    foreach (['page', 'lang', 'lastModified', 'published', 'description__hidden', 'sections.0.type', 'sections.1.type', 'sections.1.hidden'] as $structuralPath) {
        copilotSmokeAssert(!in_array($structuralPath, $paths, true), 'Structural field leaked into editable manifest: ' . $structuralPath);
    }
    copilotSmokeAssert(!in_array('visibility.passwordHash', $paths, true), 'Sensitive password hash leaked into manifest.');
    copilotSmokeAssert(!in_array('visibility.token', $paths, true), 'Sensitive token leaked into manifest.');

    copilotSmokeAssert(nibblyCopilotCan('applyField'), 'Editor should be able to apply ordinary field changes.');
    copilotSmokeAssert(nibblyCopilotCan('toggleVisibility'), 'Editor should be able to apply explicit visibility changes.');
    copilotSmokeAssert(!nibblyCopilotCan('generateImage'), 'Editor should not be able to generate images.');
    $_SESSION['admin_role'] = 'admin';
    copilotSmokeAssert(nibblyCopilotCan('generateImage'), 'Admin should be able to generate images.');
    $_SESSION['admin_role'] = 'editor';
    unset($_SESSION['nibbly_copilot_rate']);
    nibblyCopilotAssertBurstLimit('smoke-burst', 2, 60);
    nibblyCopilotAssertBurstLimit('smoke-burst', 2, 60);
    copilotSmokeRejects(fn() => nibblyCopilotAssertBurstLimit('smoke-burst', 2, 60), 'Copilot burst limit did not reject excessive requests.');
    unset($_SESSION['nibbly_copilot_rate']);

    $pageData = nibblyCopilotLoadPageData($page);
    $proposals = nibblyCopilotValidateProposals([
        'proposals' => [
            ['path' => 'title', 'value' => 'New title', 'reason' => 'Clearer heading'],
            ['path' => 'body', 'value' => '<div><h1>Bad heading</h1><p style="color:red" onclick="bad()">New <script>alert(1)</script><a href="javascript:alert(2)">link</a><strong>body</strong><em>em</em><u>u</u></p><span>span text</span></div>'],
            ['path' => 'cta.href', 'value' => 'javascript:alert(1)'],
            ['path' => 'seo.robots', 'value' => 'noindex, follow'],
        ]
    ], $context, $pageData);

    copilotSmokeAssert(count($proposals) === 3, 'Expected valid title, body, and select proposals only.');
    $duplicateFieldProposals = nibblyCopilotValidateProposals([
        'proposals' => [
            ['path' => 'body', 'value' => 'First body option', 'reason' => 'First option'],
            ['path' => 'body', 'value' => 'Second body option', 'reason' => 'Second option'],
            ['path' => 'body', 'value' => 'Final body option', 'reason' => 'Final option'],
        ]
    ], $context, $pageData);
    copilotSmokeAssert(count($duplicateFieldProposals) === 1, 'Duplicate proposals for one field should be collapsed to one option.');
    copilotSmokeAssert(($duplicateFieldProposals[0]['value'] ?? '') === 'Final body option', 'Duplicate proposal collapse should keep the final AI option.');
    $htmlProposal = null;
    foreach ($proposals as $proposal) {
        if (($proposal['path'] ?? '') === 'body') {
            $htmlProposal = $proposal;
            break;
        }
    }
    copilotSmokeAssert(is_array($htmlProposal), 'HTML proposal missing.');
    $htmlValue = (string)($htmlProposal['value'] ?? '');
    foreach (['style=', 'onclick=', '<script', 'alert(1)', 'javascript:', '<div', '<span', '<h1'] as $unsafeHtmlNeedle) {
        copilotSmokeAssert(stripos($htmlValue, $unsafeHtmlNeedle) === false, 'HTML proposal retained unsafe content: ' . $unsafeHtmlNeedle);
    }
    copilotSmokeAssert(strpos($htmlValue, '<strong>body</strong>') !== false, 'HTML proposal removed safe formatting.');
    copilotSmokeAssert(strpos($htmlValue, '<em>em</em>') !== false, 'HTML proposal removed italic formatting.');
    copilotSmokeAssert(strpos($htmlValue, '<u>u</u>') !== false, 'HTML proposal removed underline formatting.');
    $formatProposal = nibblyCopilotBuildHtmlFormatProposal($page, 'body', 'strong', 'Make this HTML field bold.');
    copilotSmokeAssert(($formatProposal['action'] ?? '') === 'formatHtmlField', 'HTML format proposal action mismatch.');
    copilotSmokeAssert(!empty($formatProposal['proposalSignature']), 'HTML format proposal signature missing.');
    copilotSmokeAssert(strpos((string)$formatProposal['value'], '<strong>') !== false, 'HTML format proposal did not add strong formatting.');
    $formatApplied = nibblyCopilotApplyFieldUpdate(
        $page,
        (string)$formatProposal['path'],
        (string)$formatProposal['value'],
        (string)$formatProposal['currentHash'],
        '',
        $formatProposal['allowedValueHashes'] ?? [],
        (string)$formatProposal['proposalSignature']
    );
    copilotSmokeAssert(strpos((string)($formatApplied['data']['body'] ?? ''), '<strong>') !== false, 'Signed HTML format proposal did not apply.');
    copilotSmokeRejects(fn() => nibblyCopilotBuildHtmlFormatProposal($page, 'title', 'strong'), 'Non-HTML field accepted an HTML formatting action.');
    copilotSmokeRejects(fn() => nibblyCopilotBuildHtmlFormatProposal($page, 'body', 'marquee'), 'Unsupported HTML formatting action was accepted.');
    foreach (['/contact', 'https://example.com/path', 'mailto:test@example.com', 'tel:+431234567'] as $safeLink) {
        copilotSmokeAssert(nibblyCopilotNormalizeSuggestionValue($safeLink, 'link') === $safeLink, 'Safe link was rejected: ' . $safeLink);
    }
    foreach (['javascript:alert(1)', 'data:text/html;base64,xxx', 'vbscript:msgbox(1)', 'ftp://example.com/file', '//example.com/path', '\\\\example.com\\share', 'file:///etc/passwd', 'https://example.com/<script>'] as $unsafeLink) {
        copilotSmokeRejects(fn() => nibblyCopilotNormalizeSuggestionValue($unsafeLink, 'link'), 'Unsafe link was accepted: ' . $unsafeLink);
    }
    $auditSummary = nibblyCopilotProposalAuditSummary($proposals);
    copilotSmokeAssert(count($auditSummary) === 3, 'Proposal audit summary count mismatch.');
    copilotSmokeAssert(isset($auditSummary[0]['valueHash']) && !isset($auditSummary[0]['value']), 'Proposal audit summary leaked raw value.');
    copilotSmokeAssert(strpos(json_encode($auditSummary, JSON_UNESCAPED_SLASHES), 'New title') === false, 'Proposal audit summary contains raw proposal text.');
    $titleProposal = $proposals[0];
    copilotSmokeAssert(!empty($titleProposal['proposalSignature']), 'Field proposal signature missing.');
    $applied = nibblyCopilotApplyFieldUpdate(
        $page,
        (string)$titleProposal['path'],
        (string)$titleProposal['value'],
        (string)$titleProposal['currentHash'],
        '',
        $titleProposal['allowedValueHashes'] ?? [],
        (string)$titleProposal['proposalSignature']
    );
    copilotSmokeAssert(($applied['data']['title'] ?? '') === 'New title', 'Signed field proposal did not apply.');
    copilotSmokeRejects(function () use ($page, $titleProposal): void {
        nibblyCopilotApplyFieldUpdate(
            $page,
            (string)$titleProposal['path'],
            'Tampered title',
            (string)$titleProposal['currentHash'],
            '',
            $titleProposal['allowedValueHashes'] ?? [],
            (string)$titleProposal['proposalSignature']
        );
    }, 'Tampered field proposal was accepted.');

    $hideProposal = nibblyCopilotBuildVisibilityProposal($page, 'title', 'hide', 'Bitte diese Überschrift ausblenden.');
    copilotSmokeAssert(($hideProposal['action'] ?? '') === 'toggleFieldVisibility', 'Visibility proposal action mismatch.');
    copilotSmokeAssert(($hideProposal['hiddenPath'] ?? '') === 'title__hidden', 'Visibility proposal hidden path mismatch.');
    copilotSmokeAssert(!empty($hideProposal['visibilitySignature']), 'Visibility proposal signature missing.');
    $hiddenApplied = nibblyCopilotApplyVisibilityUpdate(
        $page,
        (string)$hideProposal['path'],
        (string)$hideProposal['value'],
        (string)$hideProposal['currentHash'],
        (string)$hideProposal['visibilitySignature']
    );
    copilotSmokeAssert(($hiddenApplied['data']['title__hidden'] ?? false) === true, 'Signed visibility hide proposal did not apply.');
    copilotSmokeRejects(function () use ($page, $hideProposal): void {
        nibblyCopilotApplyVisibilityUpdate(
            $page,
            (string)$hideProposal['path'],
            (string)$hideProposal['value'],
            (string)$hideProposal['currentHash'],
            ''
        );
    }, 'Unsigned visibility proposal was accepted.');
    copilotSmokeWriteFixture($pageFile, json_encode($hiddenApplied['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $showProposal = nibblyCopilotBuildVisibilityProposal($page, 'title', 'show', 'Bitte diese Überschrift wieder anzeigen.');
    $shownApplied = nibblyCopilotApplyVisibilityUpdate(
        $page,
        (string)$showProposal['path'],
        (string)$showProposal['value'],
        (string)$showProposal['currentHash'],
        (string)$showProposal['visibilitySignature']
    );
    copilotSmokeAssert(!array_key_exists('title__hidden', $shownApplied['data']), 'Signed visibility show proposal did not remove hidden flag.');

    copilotSmokeRejects(fn() => nibblyCopilotNormalizeImagePath('/assets/images/generated/zz-copilot-svg-test.svg'), 'SVG image path was accepted.');
    copilotSmokeRejects(fn() => nibblyCopilotNormalizeImagePath('/assets/images/generated/zz-copilot-fake-test.png'), 'Fake raster image was accepted.');
    copilotSmokeAssert(nibblyCopilotIsExternalImageUrl('https://example.com/image.jpg'), 'External image URL was not detected.');
    copilotSmokeAssert(!nibblyCopilotIsExternalImageUrl('/assets/images/generated/zz-copilot-raster-test.png'), 'Local image path was treated as an external URL.');
    copilotSmokeRejects(fn() => nibblyCopilotAssertPublicImageUrl('http://localhost/image.jpg'), 'Localhost reference URL was accepted.');
    copilotSmokeRejects(fn() => nibblyCopilotAssertPublicImageUrl('http://127.0.0.1/image.jpg'), 'Loopback reference URL was accepted.');
    copilotSmokeRejects(fn() => nibblyCopilotAssertPublicImageUrl('http://192.168.1.10/image.jpg'), 'Private reference URL was accepted.');
    copilotSmokeAssert(
        nibblyCopilotNormalizeImagePath('/assets/images/generated/zz-copilot-raster-test.png') === '/assets/images/generated/zz-copilot-raster-test.png',
        'Valid raster image path was rejected.'
    );

    $imageProposal = nibblyCopilotImageProposal($context, 'hero.src', 'Create a brighter website hero image.', [
        'paths' => [
            '/assets/images/generated/zz-copilot-raster-test.png',
            '/assets/images/generated/zz-copilot-raster-variant-test.png'
        ]
    ]);
    copilotSmokeAssert(!empty($imageProposal['proposalSignature']), 'Image proposal signature missing.');
    copilotSmokeAssert(count($imageProposal['allowedValueHashes'] ?? []) === 2, 'Image proposal did not sign all variants.');
    $imageApplied = nibblyCopilotApplyFieldUpdate(
        $page,
        (string)$imageProposal['path'],
        '/assets/images/generated/zz-copilot-raster-variant-test.png',
        (string)$imageProposal['currentHash'],
        'Updated hero alt text',
        $imageProposal['allowedValueHashes'] ?? [],
        (string)$imageProposal['proposalSignature']
    );
    copilotSmokeAssert(
        ($imageApplied['data']['hero']['src'] ?? '') === '/assets/images/generated/zz-copilot-raster-variant-test.png',
        'Signed image variant did not apply.'
    );
    copilotSmokeAssert(($imageApplied['data']['hero']['alt'] ?? '') === 'Updated hero alt text', 'Image alt text did not apply.');
    copilotSmokeRejects(function () use ($page, $imageProposal): void {
        nibblyCopilotApplyFieldUpdate(
            $page,
            (string)$imageProposal['path'],
            '/assets/images/generated/zz-copilot-raster-test.png',
            (string)$imageProposal['currentHash'],
            '',
            [],
            ''
        );
    }, 'Unsigned image proposal was accepted.');

    $undoBackup = $page . '_2026-01-01_010101.json';
    $undoSignature = nibblyCopilotUndoSignature($page, $undoBackup, 'title');
    copilotSmokeAssert($undoSignature !== '', 'Undo signature was empty.');
    copilotSmokeAssert(!hash_equals($undoSignature, nibblyCopilotUndoSignature($page, $undoBackup, 'body')), 'Undo signature is not bound to the field path.');
    copilotSmokeAssert(!hash_equals($undoSignature, nibblyCopilotUndoSignature($page, $page . '_2026-01-01_010102.json', 'title')), 'Undo signature is not bound to the backup filename.');

    nibblyAiAudit('copilot-smoke', true, [
        'contentPage' => $page,
        'path' => 'title',
        'valueHash' => hash('sha256', 'New title')
    ]);
    $auditFile = NIBBLY_AI_AUDIT_DIR . '/' . gmdate('Y-m-d') . '.jsonl';
    copilotSmokeAssert(is_file($auditFile), 'AI audit file was not written.');
    $auditLines = array_values(array_filter(explode("\n", trim((string)file_get_contents($auditFile)))));
    copilotSmokeAssert(count($auditLines) === 1, 'Unexpected AI audit line count.');
    $auditEntry = json_decode($auditLines[0], true);
    copilotSmokeAssert(is_array($auditEntry), 'AI audit line is not valid JSON.');
    copilotSmokeAssert(($auditEntry['action'] ?? '') === 'copilot-smoke', 'AI audit action mismatch.');
    copilotSmokeAssert(($auditEntry['meta']['valueHash'] ?? '') === hash('sha256', 'New title'), 'AI audit hash metadata missing.');
    copilotSmokeAssert(strpos($auditLines[0], 'New title') === false, 'AI audit entry leaked raw proposal text.');

    $draft = nibblyCopilotSignCreateDraft(nibblyCopilotNormalizeCreateDraft([
        'contentType' => 'event',
        'missing' => [],
        'draft' => [
            'title' => 'AI Workshop',
            'lang' => 'en',
            'date' => '2026-07-12',
            'time' => '18:00',
            'location' => 'Studio',
            'description' => '<p>Workshop</p>'
        ]
    ], $context));
    copilotSmokeAssert(nibblyCopilotVerifyCreateDraftSignature($draft), 'Signed event draft did not verify.');
    $tamperedDraft = $draft;
    $tamperedDraft['draft']['title'] = 'Changed';
    copilotSmokeAssert(!nibblyCopilotVerifyCreateDraftSignature($tamperedDraft), 'Tampered event draft signature verified.');
    $missingPayloadDraft = $draft;
    unset($missingPayloadDraft['draft']);
    copilotSmokeAssert(!nibblyCopilotVerifyCreateDraftSignature($missingPayloadDraft), 'Draft signature verified without draft payload.');
    $created = nibblyCopilotBuildCreatedContent($draft['contentType'], $draft['draft']);
    copilotSmokeAssert(($created['hidden'] ?? false) === true, 'Created event draft is not hidden by default.');
    copilotSmokeAssert(($created['id'] ?? '') === '2026-07-12-ai-workshop', 'Created event id is not deterministic.');
    $publishedEventWrapper = nibblyCopilotPublishEventData(['events' => [$created]], $created['id']);
    copilotSmokeAssert(empty($publishedEventWrapper['events'][0]['hidden']), 'Published event still has hidden flag.');

    $newsDraft = nibblyCopilotSignCreateDraft(nibblyCopilotNormalizeCreateDraft([
        'contentType' => 'news',
        'missing' => [],
        'draft' => [
            'title' => 'AI News',
            'slug' => 'ai-news',
            'lang' => 'en',
            'date' => '2026-07-13',
            'excerpt' => 'Short update',
            'content' => '<p>News body</p>',
            'author' => 'Editor'
        ]
    ], $context));
    copilotSmokeAssert(nibblyCopilotVerifyCreateDraftSignature($newsDraft), 'Signed news draft did not verify.');
    $createdNews = nibblyCopilotBuildCreatedContent($newsDraft['contentType'], $newsDraft['draft']);
    copilotSmokeAssert(($createdNews['hidden'] ?? false) === true, 'Created news draft is not hidden by default.');
    copilotSmokeAssert(($createdNews['id'] ?? '') === '2026-07-13-ai-news', 'Created news id is not deterministic.');
    $publishedNews = nibblyCopilotPublishNewsData($createdNews);
    copilotSmokeAssert(!array_key_exists('hidden', $publishedNews), 'Published news still has hidden flag.');

    $pageDraft = nibblyCopilotSignCreateDraft(nibblyCopilotNormalizeCreateDraft([
        'contentType' => 'page',
        'missing' => [],
        'draft' => [
            'title' => 'AI Landing',
            'slug' => 'ai-landing',
            'lang' => 'en',
            'description' => 'Draft page',
            'content' => '<p>Page body</p>'
        ]
    ], $context));
    copilotSmokeAssert(nibblyCopilotVerifyCreateDraftSignature($pageDraft), 'Signed page draft did not verify.');
    $createdPage = nibblyCopilotBuildCreatedContent($pageDraft['contentType'], $pageDraft['draft']);
    copilotSmokeAssert(($createdPage['pageName'] ?? '') === 'en_ai-landing', 'Created page name is not deterministic.');
    copilotSmokeAssert(($createdPage['content']['visibility']['status'] ?? '') === 'private', 'Created page draft is not private by default.');
    copilotSmokeAssert(($createdPage['content']['nav'] ?? null) === [], 'Created page draft should not be in navigation by default.');
    $publishedPage = nibblyCopilotPublishPageData($createdPage['content']);
    copilotSmokeAssert(!array_key_exists('visibility', $publishedPage), 'Published page still has private visibility block.');

    echo json_encode([
        'ok' => true,
        'checks' => [
            'safeContext',
            'noAiCredentialLeak',
            'safeKnowledgeBase',
            'accurateRuntimeCapabilities',
            'rolePermissions',
            'copilotBurstLimit',
            'signedFieldProposal',
            'signedHtmlFormatProposal',
            'signedVisibilityProposal',
            'strictHtmlSanitization',
            'strictLinkAllowlist',
            'tamperRejection',
            'rasterImageValidation',
            'signedImageVariant',
            'imageAltApply',
            'auditSummaryHashesOnly',
            'undoSignatureBinding',
            'auditJsonl',
            'signedContentDraft',
            'hiddenEventDraft',
            'hiddenNewsDraft',
            'privatePageDraft',
            'publishHelpers'
        ]
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
} finally {
    foreach (array_reverse($createdFiles) as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    foreach (array_reverse($createdDirs) as $dir) {
        copilotSmokeRemoveDir($dir);
    }
}
