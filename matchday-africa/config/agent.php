<?php

// The agent role (the social media assistant) and its API, /api/agent (docs/agent-api.md).
return [
    // Requests a minute per token, and writes (renders, drafts, posted marks) a minute per token.
    'per_minute' => (int) env('AGENT_API_PER_MINUTE', 120),
    'writes_per_minute' => (int) env('AGENT_API_WRITES_PER_MINUTE', 20),
    // Page sizes.
    'max_limit' => 100,
];
