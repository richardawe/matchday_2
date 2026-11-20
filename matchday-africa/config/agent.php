<?php

// The agent role (the social media assistant) and its API, /api/agent (docs/agent-api.md).
return [
    // Requests a minute per token, and writes (renders, drafts, posted marks) a minute per token.
    'per_minute' => (int) env('AGENT_API_PER_MINUTE', 120),
    'writes_per_minute' => (int) env('AGENT_API_WRITES_PER_MINUTE', 20),
    // The agent may publish and unpublish the articles it wrote. An admin can switch this off at /admin/settings,
    // which wins over this value.
    'can_publish' => (bool) env('AGENT_CAN_PUBLISH', true),
    // Page sizes.
    'max_limit' => 100,
];
