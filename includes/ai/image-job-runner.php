<?php
/**
 * Shared AI image job runner.
 *
 * Used by admin/api.php and the local CLI worker so long-running image
 * generation does not have to occupy the PHP built-in web server request.
 */

function nibblyAiRunImageJobCore(string $jobId, ?callable $assertAccess = null): array {
    $existingJob = nibblyAiLoadImageJob($jobId);
    $existingJob = nibblyAiRefreshImageJobState($existingJob);
    if ($assertAccess) {
        $assertAccess($existingJob);
    }
    if ((string)($existingJob['status'] ?? '') === 'running') {
        return nibblyAiPublicImageJob($existingJob);
    }
    if (in_array((string)($existingJob['status'] ?? ''), ['success', 'error'], true)) {
        return nibblyAiPublicImageJob($existingJob);
    }
    $job = nibblyAiMarkImageJobRunning($jobId);
    if (in_array((string)($job['status'] ?? ''), ['success', 'error'], true)) {
        return nibblyAiPublicImageJob($job);
    }

    $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
    $options = is_array($payload['options'] ?? null) ? $payload['options'] : [];
    $prompt = trim((string)($payload['prompt'] ?? ''));
    $kind = (string)($job['kind'] ?? 'dashboard');

    try {
        if ($kind === 'copilot') {
            $contentPage = trim((string)($payload['contentPage'] ?? ''));
            $fieldRef = trim((string)($payload['fieldRef'] ?? ''));
            $instruction = trim((string)($payload['instruction'] ?? ''));
            if ($contentPage === '' || $fieldRef === '' || $instruction === '' || $prompt === '') {
                throw new RuntimeException('Image job is missing required copilot data.');
            }
            $settings = nibblyAiLoadSettings(false);
            nibblyAiEnsureEnabled($settings);
            nibblyAiEnsureFeature($settings, 'imageGeneration');
            $context = nibblyCopilotBuildContext($contentPage, nibblyAiLoadSettings(true));
            $fields = nibblyCopilotAllowedImageFields($context, $fieldRef);
            if (!$fields) {
                throw new RuntimeException('Target field does not accept AI image generation.');
            }
            $field = $fields[0];
            $result = nibblyAiGenerateImage($prompt, $options);
            $proposal = nibblyCopilotImageProposal($context, $field['id'], $instruction, $result);
            nibblyAiAudit('copilot-generate-image', true, [
                'jobId' => $jobId,
                'contentPage' => $contentPage,
                'path' => $proposal['path'],
                'imageMode' => (string)($payload['imageMode'] ?? 'generate'),
                'requestedCount' => (int)($options['count'] ?? 1),
                'count' => count($proposal['paths'] ?? []),
                'proposals' => nibblyCopilotProposalAuditSummary([$proposal])
            ]);
            return nibblyAiFinishImageJob($jobId, [
                'proposal' => $proposal,
                'usage' => $result['usage'] ?? null,
                'limits' => $result['limits'] ?? null,
                'context' => $context
            ]);
        }

        if ($prompt === '') {
            throw new RuntimeException('Image job prompt is missing.');
        }
        $result = nibblyAiGenerateImage($prompt, $options);
        return nibblyAiFinishImageJob($jobId, $result);
    } catch (Throwable $e) {
        if ($kind === 'dashboard') {
            $settings = nibblyAiLoadSettings(false);
            nibblyAiRecordImageHistory([
                'status' => 'error',
                'model' => (string)($options['model'] ?? $settings['imageModel'] ?? ''),
                'prompt' => $prompt,
                'size' => (string)($options['size'] ?? ''),
                'aspectRatio' => (string)($options['aspectRatio'] ?? ''),
                'quality' => (string)($options['quality'] ?? ''),
                'format' => (string)($options['outputFormat'] ?? ''),
                'moderation' => (string)($options['moderation'] ?? ''),
                'compression' => (int)($options['outputCompression'] ?? 0),
                'count' => (int)($options['count'] ?? 0),
                'referenceImages' => nibblyAiPublicReferenceList($options),
                'outputs' => [],
                'error' => $e->getMessage(),
                'estimatedCostCents' => 0
            ]);
        }
        nibblyAiAudit($kind === 'copilot' ? 'copilot-generate-image' : 'image', false, ['jobId' => $jobId, 'message' => $e->getMessage()]);
        return nibblyAiFailImageJob($jobId, $e->getMessage());
    }
}
