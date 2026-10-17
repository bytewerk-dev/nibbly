<?php

declare(strict_types=1);

// Image services: registry, shape mapping, request builders, custom parameters, price resolution, trusted addresses, key-free settings for the browser.

function imgAssert(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function imgSame($expected, $actual, string $message): void {
    imgAssert($expected === $actual, $message . ' (expected ' . json_encode($expected) . ', got ' . json_encode($actual) . ')');
}

$root = dirname(__DIR__);
$site = sys_get_temp_dir() . '/nibbly-imgsvc-' . bin2hex(random_bytes(4));
mkdir($site . '/content', 0755, true);
register_shutdown_function(static fn() => exec('rm -rf ' . escapeshellarg($site)));
foreach (['includes', 'admin', 'VERSION'] as $item) exec('cp -R ' . escapeshellarg($root . '/' . $item) . ' ' . escapeshellarg($site . '/'));
foreach (['admin/config.php', 'admin/smtp-config.php', 'includes/nav-config.php'] as $local) @unlink($site . '/' . $local);
define('SITE_LANG_DEFAULT', 'en');
$_SESSION = ['admin_role' => 'admin', 'admin_user_id' => 'u_admin', 'admin_username' => 'admin'];
require_once $site . '/includes/ai/ai-helper.php';

// ---- Registry --------------------------------------------------------------------------------------------------------------------
$registry = nibblyAiImageServiceRegistry();
imgSame(NIBBLY_AI_IMAGE_SERVICE_IDS, array_keys($registry['services']), 'every service id is in the registry');
$adapters = ['openai-images', 'openrouter-chat', 'kie-task', 'fal-queue', 'gemini-generate'];
foreach ($registry['services'] as $id => $entry) {
    imgAssert(in_array($entry['adapter'], $adapters, true), "{$id} names an adapter that exists");
    imgAssert(str_starts_with((string)$entry['label'], 'ai.image_service_'), "{$id} label is a translation key");
    foreach ((array)($entry['models'] ?? []) as $model) {
        imgAssert(isset($model['id'], $model['label']) && array_key_exists('verified', $model) && array_key_exists('price', $model), "{$id}/{$model['id']} has id, label, verified and price");
        imgAssert($model['price'] === null || (isset($model['price']['perImage'], $model['price']['source'], $model['price']['updatedAt'])), "{$id}/{$model['id']}: a price needs its source and date");
        foreach ((array)($model['sizePresets'] ?? []) as $ratio => $preset) imgAssert(in_array($ratio, $model['aspects'], true), "{$id}/{$model['id']}: preset for an unlisted ratio");
    }
}
foreach (['fal', 'comet'] as $id) imgAssert(nibblyAiImageModelSpec($id, $registry['services'][$id]['defaultModel'])['known'] === true, "{$id} default model is listed");
imgAssert(nibblyAiImageModelSpec('fal', 'fal-ai/unheard-of')['known'] === false && nibblyAiImageModelSpec('fal', 'fal-ai/unheard-of')['verified'] === false, 'an unknown model gets the unverified defaults');
$ui = nibblyAiImageServicesForUi();
imgAssert(!str_contains(json_encode($ui), 'apiKey'), 'the registry for the browser has no key field');

// ---- Shape ---------------------------------------------------------------------------------------------------------------------------
imgSame('16:9', nibblyAiImageRequestedRatio(['aspectRatio' => '16:9', 'size' => '1024x1024']), 'the ratio wins over the size');
imgSame('3:2', nibblyAiImageRequestedRatio(['aspectRatio' => 'auto', 'size' => '1536x1024']), 'a size becomes its ratio');
imgSame('auto', nibblyAiImageRequestedRatio(['aspectRatio' => 'auto', 'size' => 'auto']), 'nothing asked, nothing sent');
imgSame('16:9', nibblyAiImageNearestAspect('7:4', ['1:1', '16:9', '4:3']), 'nearest ratio');
imgSame('1:1', nibblyAiImageNearestAspect('1:1', ['21:9', '1:1']), 'exact ratio');
imgSame('4K', nibblyAiImageResolutionFor(3840, ['1K', '2K', '4K']), '4K');
imgSame('2K', nibblyAiImageResolutionFor(3840, ['1K', '2K']), 'the highest step the model has');
imgSame('1K', nibblyAiImageResolutionFor(1024, ['1K', '2K']), '1K');
imgSame('1536x1024', nibblyAiImageCustomSize('16:9'), 'landscape size');
imgSame('1024x1536', nibblyAiImageCustomSize('9:16'), 'portrait size');
imgSame('1024x1024', nibblyAiImageCustomSize('1:1'), 'square size');

// ---- Request builders ---------------------------------------------------------------------------------------------------------------
$fal = nibblyAiImageModelSpec('fal', 'fal-ai/nano-banana-2');
$service = ['extraParams' => ['seed' => 7]];
$input = nibblyAiFalInput($fal, $service, ['prompt' => 'x', 'aspectRatio' => '16:9', 'size' => 'auto', 'outputFormat' => 'webp', 'imageScale' => 2048], [], 3);
imgSame(['prompt' => 'x', 'aspect_ratio' => '16:9', 'num_images' => 3, 'output_format' => 'webp', 'resolution' => '2K', 'seed' => 7], $input, 'fal input');
$flux = nibblyAiImageModelSpec('fal', 'fal-ai/flux/schnell');
$input = nibblyAiFalInput($flux, ['extraParams' => []], ['prompt' => 'y', 'aspectRatio' => '3:2', 'size' => 'auto', 'outputFormat' => 'png'], [], 1);
imgSame(['prompt' => 'y', 'image_size' => 'landscape_4_3', 'output_format' => 'png'], $input, 'fal input with size presets: nearest ratio, then the preset');
$comet = nibblyAiImageModelSpec('comet', 'gemini-3.1-flash-image-preview');
$body = nibblyAiGeminiBody($comet, ['extraParams' => []], ['prompt' => 'z', 'aspectRatio' => '1:1', 'size' => 'auto', 'imageScale' => 1024], []);
imgSame(['contents' => [['role' => 'user', 'parts' => [['text' => 'z']]]], 'generationConfig' => ['responseModalities' => ['TEXT', 'IMAGE'], 'imageConfig' => ['aspectRatio' => '1:1', 'imageSize' => '1K']]], $body, 'Gemini body');
[$binary, $texts] = nibblyAiGeminiExtract(['candidates' => [['content' => ['parts' => [
    ['thought' => true, 'inlineData' => ['data' => base64_encode('thought')]], ['text' => 'hello'], ['inline_data' => ['data' => base64_encode('IMG')]]]]]]]);
imgSame('IMG', $binary, 'the thought part is skipped, both spellings of inline data are read');
imgSame(['hello'], $texts, 'texts come along');
[$none] = nibblyAiGeminiExtract(['candidates' => [['content' => ['parts' => [['text' => 'only text']]]]]]);
imgSame(null, $none, 'a text-only answer has no image');
imgSame('model refused', nibblyAiProviderErrorMessage(['detail' => [['msg' => 'model refused']]]), 'fal validation errors');
imgSame('plain', nibblyAiProviderErrorMessage(['detail' => 'plain']), 'detail as text');
imgSame('nope', nibblyAiProviderErrorMessage(['error' => ['message' => 'nope']]), 'OpenAI errors');
imgSame('fallback', nibblyAiProviderErrorMessage('<html>', 'fallback'), 'no body: the fallback');

// ---- Custom parameters ------------------------------------------------------------------------------------------------------------------
imgSame([], nibblyAiCleanImageExtraParams(''), 'empty');
imgSame(['a' => 1, 'b' => ['c' => true]], nibblyAiCleanImageExtraParams('{"a":1,"b":{"c":true}}'), 'JSON text');
foreach (['[1,2]', '"text"', '{"prompt":"x"}', '{"MODEL":"x"}', '{"bad key":1}', '{"a":{"b":{"c":{"d":{"e":1}}}}}', '{"a":"' . str_repeat('x', 4100) . '"}', 'not json'] as $bad) {
    $failed = false;
    try { nibblyAiCleanImageExtraParams($bad); } catch (RuntimeException $e) { $failed = true; }
    imgAssert($failed, 'refused: ' . substr($bad, 0, 40));
}

// ---- Addresses the provider hands back -----------------------------------------------------------------------------------------------------
imgAssert(nibblyAiImageTrustedUrl('https://queue.fal.run/fal-ai/x/requests/1/status', 'https://queue.fal.run'), 'same origin is followed');
imgAssert(!nibblyAiImageTrustedUrl('https://evil.example/status', 'https://queue.fal.run') && !nibblyAiImageTrustedUrl('http://queue.fal.run/status', 'https://queue.fal.run') && !nibblyAiImageTrustedUrl('https://user:pw@queue.fal.run/x', 'https://queue.fal.run') && !nibblyAiImageTrustedUrl('https://queue.fal.run:8443/x', 'https://queue.fal.run'), 'other host, scheme, credentials or port: not followed');

// ---- Resolution and key source ---------------------------------------------------------------------------------------------------------------
$base = nibblyAiDefaults();
$base['provider'] = 'anthropic';
$base['providerCredentials']['anthropic']['apiKey'] = 'chat-key';
$base['baseUrl'] = 'https://api.anthropic.com/v1';
$base['apiKey'] = 'chat-key';
$resolved = nibblyAiImageServiceResolve($base);
imgAssert($resolved['inherit'] && !$resolved['supported'] && $resolved['reason'] === 'chat_provider_without_images', 'Anthropic with "inherit" has no images');
imgAssert(!nibblyAiImageServiceUsable($base) && nibblyAiImageUnavailableReason($base) === 'chat_provider_without_images', '... and says why');
$withFal = $base;
$withFal['imageService'] = ['provider' => 'fal', 'model' => '', 'models' => [], 'custom' => []];
imgAssert(!nibblyAiImageServiceUsable($withFal) && nibblyAiImageUnavailableReason($withFal) === 'key_missing', 'fal without a key');
$withFal['imageServices'] = ['fal' => ['apiKey' => 'fal-key']];
$resolved = nibblyAiImageServiceResolve($withFal);
imgAssert(nibblyAiImageServiceUsable($withFal) && $resolved['model'] === 'fal-ai/nano-banana-2' && $resolved['keySource'] === 'own' && $resolved['baseUrl'] === 'https://queue.fal.run', 'fal with a key: default model and base URL');
$effective = nibblyAiImageEffectiveSettings($withFal, $resolved);
imgAssert($effective['provider'] === 'fal' && $effective['apiKey'] === 'fal-key' && $effective['imageModel'] === 'fal-ai/nano-banana-2' && $effective['chatModel'] === $base['chatModel'], 'effective settings: the image service credentials, everything else from the chat settings');
$openai = $base;
$openai['providerCredentials']['openai-compatible']['apiKey'] = 'openai-chat-key';
$openai['imageService'] = ['provider' => 'openai-compatible', 'model' => '', 'models' => [], 'custom' => []];
$resolved = nibblyAiImageServiceResolve($openai);
imgAssert($resolved['apiKey'] === 'openai-chat-key' && $resolved['keySource'] === 'chat' && $resolved['model'] === 'gpt-image-2' && $resolved['adapter'] === 'openai-images', 'an OpenAI image service uses the OpenAI chat key when it has none of its own');
$foreign = $openai;
$foreign['providerCredentials']['openai-compatible']['baseUrl'] = 'https://api.groq.com/openai/v1';
$resolved = nibblyAiImageServiceResolve($foreign);
imgAssert($resolved['apiKey'] === '' && $resolved['keySource'] === 'none' && nibblyAiImageUnavailableReason($foreign) === 'key_missing', 'a chat key for another host is not used for OpenAI images');
imgAssert(nibblyAiImageServicePublic($foreign)['services']['openai-compatible']['chatKeyAvailable'] === false, '... and not offered as available');
$openai['imageServices'] = ['openai-compatible' => ['apiKey' => 'own-key']];
imgSame('own-key', nibblyAiImageServiceResolve($openai)['apiKey'], 'an own key wins');
$custom = $base;
$custom['imageService'] = ['provider' => 'custom', 'model' => 'm', 'models' => [], 'custom' => ['baseUrl' => 'https://img.example.com/v1/', 'sizeMode' => 'bogus', 'authStyle' => 'x-api-key', 'extraParams' => ['a' => 1]]];
$custom['imageServices'] = ['custom' => ['apiKey' => 'k']];
$resolved = nibblyAiImageServiceResolve($custom);
imgAssert($resolved['baseUrl'] === 'https://img.example.com/v1' && $resolved['sizeMode'] === 'size' && $resolved['authStyle'] === 'x-api-key' && $resolved['extraParams'] === ['a' => 1], 'custom service: trimmed URL, size mode limited to known values');
$custom['imageService']['custom']['baseUrl'] = '';
imgSame('custom_base_url_missing', nibblyAiImageUnavailableReason($custom), 'custom without a base URL');
imgSame('x-api-key: k', nibblyAiImageAuthHeader(['apiKey' => 'k', 'authStyle' => 'x-api-key']), 'x-api-key header');
imgSame('Authorization: Bearer k', nibblyAiImageAuthHeader(['apiKey' => 'k']), 'bearer header');

// ---- Prices -----------------------------------------------------------------------------------------------------------------------------------
$svc = nibblyAiImageServiceResolve($withFal);
$settings = ['pricing' => ['imageCentsPerRequest' => 0, 'imageModels' => [], 'usdPerEur' => 0.0]];
imgSame(null, nibblyAiImagePrice($settings, $svc, 'fal-ai/nano-banana-2'), 'no price anywhere: unknown, not free');
$settings['pricing']['imageCentsPerRequest'] = 5;
$price = nibblyAiImagePrice($settings, $svc, 'fal-ai/nano-banana-2');
imgAssert($price['source'] === 'default' && $price['cents'] === 5.0, 'the default price per image is the fallback');
$settings['pricing']['imageModels']['fal|fal-ai/nano-banana-2'] = ['perImage' => 0.5, 'currency' => 'EUR'];
$price = nibblyAiImagePrice($settings, $svc, 'FAL-AI/Nano-Banana-2');
imgAssert($price['source'] === 'override' && $price['cents'] === 50.0, 'a price for the model wins (model ids compare in lower case)');
$settings['pricing']['imageModels']['fal|fal-ai/nano-banana-2'] = ['perImage' => 0.04, 'currency' => 'USD'];
$settings['pricing']['usdPerEur'] = 1.0;
imgSame(4.0, nibblyAiImagePrice($settings, $svc, 'fal-ai/nano-banana-2')['cents'], 'USD with the own rate');
$withCatalog = $svc;
$withCatalog['spec']['price'] = ['perImage' => 0.1, 'currency' => 'EUR', 'source' => 'x', 'updatedAt' => '2026-10-17'];
$settings['pricing']['imageModels'] = [];
imgAssert(nibblyAiImagePrice($settings, $withCatalog, 'm')['source'] === 'catalog' && nibblyAiImagePrice($settings, $withCatalog, 'm')['cents'] === 10.0, 'the registry price comes before the default');
imgSame(['fal|fal-ai/nano-banana-2' => ['perImage' => 0.5, 'currency' => 'EUR']],
    nibblyAiCleanImagePriceOverrides(['fal|fal-ai/nano-banana-2' => ['perImage' => '0.5', 'currency' => 'EUR'], 'bad' => ['perImage' => 1], 'fal|x y' => ['perImage' => 1]], []), 'only valid keys are kept');
imgSame([], nibblyAiCleanImagePriceOverrides(['fal|m' => null, 'fal|n' => ['perImage' => '']], ['fal|m' => ['perImage' => 1, 'currency' => 'USD'], 'fal|n' => ['perImage' => 1, 'currency' => 'USD']]), 'null or empty removes a price');

// ---- Prices by resolution and quality --------------------------------------------------------------------------------------------------
$settings = ['pricing' => ['imageCentsPerRequest' => 5, 'usdPerEur' => 1.0, 'imageModels' => ['fal|fal-ai/nano-banana-2' => [
    'perImage' => 0.05, 'currency' => 'USD', 'byResolution' => ['1K' => 0.04, '4K' => 0.12], 'byQuality' => ['high' => 0.2]]]]];
$price = static function (array $request) use (&$settings, $svc) { return nibblyAiImagePrice($settings, $svc, 'fal-ai/nano-banana-2', $request); };
imgSame([4.0, 'resolution:1K'], [$price(['resolution' => '1K', 'quality' => 'high'])['cents'], $price(['resolution' => '1K'])['basis']], 'a price for the resolution comes first');
imgSame([12.0, 'resolution:4K'], [$price(['resolution' => '4K'])['cents'], $price(['resolution' => '4K'])['basis']], '4K');
imgSame([20.0, 'quality:high'], [$price(['resolution' => '2K', 'quality' => 'high'])['cents'], $price(['resolution' => '2K', 'quality' => 'high'])['basis']], 'no price for 2K: the one for the quality');
imgSame([5.0, 'any'], [$price(['resolution' => '2K', 'quality' => 'auto'])['cents'], $price(['resolution' => '2K', 'quality' => 'auto'])['basis']], 'no match: the price for any image');
unset($settings['pricing']['imageModels']['fal|fal-ai/nano-banana-2']['perImage']);
imgSame([4.0, 'resolution:1K', 5.0, 'default'], [$price(['resolution' => '1K'])['cents'], $price(['resolution' => '1K'])['basis'], $price(['resolution' => '2K'])['cents'], $price(['resolution' => '2K'])['source']], 'a table without a price for any image: a miss falls back to the default price of the settings');
$settings['pricing']['imageCentsPerRequest'] = 0;
imgSame(null, $price(['resolution' => '2K']), 'a miss without any default: price not on file');
$table = nibblyAiImagePriceTable($settings, $svc, 'fal-ai/nano-banana-2');
imgSame(['1K' => 4.0, '4K' => 12.0], $table['byResolution'], 'the table in euro cents (rate 1)');
imgSame(['fal|fal-ai/nano-banana-2' => ['perImage' => 0.05, 'currency' => 'EUR', 'byResolution' => ['1K' => 0.04, '2K' => 0.06], 'byQuality' => ['low' => 0.01]]],
    nibblyAiCleanImagePriceOverrides(['fal|fal-ai/nano-banana-2' => ['perImage' => '0.05', 'currency' => 'EUR', 'byResolution' => ['1K' => '0.04', '2K' => 0.06, '8K' => 1, '4K' => ''], 'byQuality' => ['low' => '0.01', 'ultra' => 3]]], []), 'tables are cleaned: known steps, numbers only');
imgSame([], nibblyAiCleanImagePriceOverrides(['fal|m' => ['perImage' => '', 'byResolution' => ['1K' => '']]], ['fal|m' => ['perImage' => 1, 'currency' => 'USD']]), 'an entry with no number left removes the price');
imgSame(['fal|m' => ['currency' => 'USD', 'byQuality' => ['high' => 0.2]]], nibblyAiCleanImagePriceOverrides(['fal|m' => ['byQuality' => ['high' => 0.2]]], []), 'a table alone is a valid entry');
$resolutionOf = static fn(string $id, string $model, array $options) => nibblyAiImageRequestResolution(nibblyAiImageServiceResolve(['provider' => 'anthropic', 'baseUrl' => 'x', 'imageService' => ['provider' => $id, 'model' => $model, 'custom' => []]] + $base), $model, $options);
imgSame('2K', $resolutionOf('fal', 'fal-ai/nano-banana-2', ['imageScale' => 2048]), 'fal resolution step');
imgSame('', $resolutionOf('fal', 'fal-ai/flux/schnell', ['imageScale' => 2048]), 'a model without resolutions has none');
imgSame('4K', $resolutionOf('comet', 'gemini-3.1-flash-image-preview', ['imageScale' => 3840]), 'Comet resolution step');
imgSame('1K', $resolutionOf('kie', 'nano-banana-2', ['imageScale' => 1024]), 'Kie Nano Banana resolution step');
imgSame('', $resolutionOf('kie', 'gpt-image-2', ['imageScale' => 3840]), 'Kie GPT Image has none');
imgSame('', $resolutionOf('openai-compatible', 'gpt-image-2', ['imageScale' => 3840]), 'OpenAI has none');

// ---- Model ids and ports ----------------------------------------------------------------------------------------------------------------------
imgSame('fal-ai/other/path', nibblyAiCleanModelAllowEmpty('fal-ai/../other/./path'), 'dot segments are removed from model ids');
imgSame('a/b', nibblyAiCleanModelId('/a//b/'), 'empty segments too');
imgSame('gpt-image-2', nibblyAiCleanModelAllowEmpty('gpt-image-2'), 'ordinary ids are unchanged');
$resolver = static fn(string $host): array => ['93.184.216.34'];
$failed = false;
try { nibblyNetPrepareUrl('https://cdn.example.com:8443/x.png', ['resolver' => $resolver]); } catch (NibblyNetException $e) { $failed = $e->reason === 'port'; }
imgAssert($failed, 'other ports are refused by default');
imgSame(8443, nibblyNetPrepareUrl('https://cdn.example.com:8443/x.png', ['resolver' => $resolver, 'extraPorts' => [8080, 8443]])['port'], 'a caller can allow the usual alternative ports');
$failed = false;
try { nibblyNetPrepareUrl('https://cdn.example.com:9999/x.png', ['resolver' => $resolver, 'extraPorts' => [8080, 8443]]); } catch (NibblyNetException $e) { $failed = $e->reason === 'port'; }
imgAssert($failed, '... but only those');

// ---- Reference images ------------------------------------------------------------------------------------------------------------------------
$refs = static fn(string $id, string $model) => nibblyAiImageServiceSupportsReferences(nibblyAiImageServiceResolve(['provider' => 'anthropic', 'baseUrl' => 'x', 'imageService' => ['provider' => $id, 'model' => $model, 'custom' => ['baseUrl' => 'https://x.example/v1']]] + $base));
imgAssert($refs('fal', 'fal-ai/nano-banana-2') && !$refs('fal', 'fal-ai/flux/schnell') && !$refs('fal', 'fal-ai/new'), 'fal takes references only where an edit endpoint is known');
imgAssert($refs('comet', 'gemini-3.1-flash-image-preview') && $refs('kie', 'nano-banana-2') && $refs('openrouter', 'google/gemini-3.1-flash-image-preview') && $refs('openai-compatible', 'gpt-image-2') && $refs('custom', 'm'), 'the other adapters take references');
imgAssert(!nibblyAiImageServiceSupportsReferences(nibblyAiImageServiceResolve($base)), 'no image service, no references');

// ---- Live model lists ------------------------------------------------------------------------------------------------------------------------
$failed = false;
try { nibblyAiImageLiveModels($base, 'kie'); } catch (RuntimeException $e) { $failed = true; }
imgAssert($failed, 'only fal and Comet have a model list');
imgSame('https://api.fal.ai', nibblyAiImageLiveBase('fal'), 'fal list host');
imgSame('https://api.cometapi.com', nibblyAiImageLiveBase('comet'), 'Comet list host');
imgSame([['id' => 'a/B', 'label' => 'x'], ['id' => 'c/d', 'label' => 'y']], nibblyAiImageLiveSorted(['c/d' => ['id' => 'c/d', 'label' => 'y'], 'a/B' => ['id' => 'a/B', 'label' => 'x']]), 'lists are sorted');

// ---- Settings for the browser carry no key ------------------------------------------------------------------------------------------------
$input = ['imageService' => ['provider' => 'fal', 'model' => 'fal-ai/nano-banana-2', 'custom' => ['extraParams' => '{"seed":1}']], 'imageServices' => ['fal' => ['apiKey' => 'typed-key'], 'comet' => ['apiKey' => '••••']]];
$parsed = nibblyAiImageServiceFromInput($input, $base, false);
imgSame(['fal' => 'typed-key'], $parsed['keys'], 'only a real key counts, a mask never');
imgSame(['seed' => 1], $parsed['imageService']['custom']['extraParams'], 'extra parameters parsed');
imgSame(['fal' => 'fal-ai/nano-banana-2'], $parsed['imageService']['models'], 'the model of the service is remembered');
$failed = false;
try { nibblyAiImageServiceFromInput(['imageService' => ['provider' => 'nope']], $base, false); } catch (RuntimeException $e) { $failed = true; }
imgAssert($failed, 'unknown service refused');
$failed = false;
try { nibblyAiImageServiceFromInput(['imageService' => ['provider' => 'custom', 'custom' => ['baseUrl' => 'http://127.0.0.1:9/v1']]], $base, false); } catch (RuntimeException $e) { $failed = true; }
imgAssert($failed, 'a local base URL needs the permission');
nibblyAiImageServiceFromInput(['imageService' => ['provider' => 'custom', 'custom' => ['baseUrl' => 'http://127.0.0.1:9/v1']]], $base, true);

echo "PASS image service helpers: registry, shape mapping, request builders, custom parameters, trusted addresses, key sources, prices\n";
