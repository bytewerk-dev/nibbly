<?php
/**
 * Agent settings and rights (agent phase P4). The settings, rights and availability checks live in
 * agent-config.php; this file adds the acting user for the change set functions.
 *
 * The settings live in the "agent" section of content/ai-settings.json and are read from
 * the raw file: nibblyAiSaveSettings() rebuilds that file from a whitelist, and extending it
 * (with masked keys for research and stock images) belongs to the settings UI phase.
 *
 *   agent.enabled                 master switch (default off)
 *   agent.limits.*                per-round limits, cost cap per run, rate limits
 *   agent.roles.editor.*          what editors may do; admins may do everything
 *
 * "edit" (change existing text, images, links) is implicit for every editor and admin.
 */

require_once __DIR__ . '/agent-config.php';
require_once __DIR__ . '/changeset.php';

/** Who is acting, in the shape the change set functions expect. */
function nibblyAgentActor(): array {
    return [
        'userKey' => nibblyAgentUserKey(),
        'isAdmin' => nibblyAgentRole() === 'admin',
        'canPublish' => nibblyAgentCan('publish'),
    ];
}
