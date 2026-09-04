<?php
/** Shared provider capabilities and UI presets. Custom text models remain supported. */
function nibblyAiModelCatalog(): array {
    static $catalog;
    return $catalog ??= json_decode((string)file_get_contents(__DIR__ . '/model-catalog.json'), true, 512, JSON_THROW_ON_ERROR);
}
function nibblyAiCatalogImageModel(string $provider, string $model): string {
    $entry = nibblyAiModelCatalog()[$provider];
    $model = $entry['imageAliases'][$model] ?? $model;
    if ($provider === 'openrouter') return $model; // Live/custom image models are valid beyond the fallback presets.
    $valid = array_column($entry['images'], 'value');
    return in_array($model, $valid, true) ? $model : ($valid[0] ?? '');
}
