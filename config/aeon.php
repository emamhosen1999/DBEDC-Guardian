<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Aeon AI Assistant Master Switch
    |--------------------------------------------------------------------------
    |
    | Enable or disable Aeon across DBEDC Guardian.
    |
    */
    'enabled' => (bool) env('AEON_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Active AI Provider
    |--------------------------------------------------------------------------
    |
    | Supported: "gemini", "openai" (covers OpenAI, DeepSeek, OpenRouter, Ollama)
    |
    */
    'provider' => env('AEON_PROVIDER', 'gemini'),

    /*
    |--------------------------------------------------------------------------
    | System Prompt & Domain Personas
    |--------------------------------------------------------------------------
    |
    | Aeon is the intelligent Enterprise Copilot embedded directly in
    | Dhaka Bypass Expressway Development Company (DBEDC) Guardian ERP.
    |
    */
    'system_prompt' => <<<'PROMPT'
You are Aeon, the advanced AI Operations & Quality Intelligence Copilot for Dhaka Bypass Expressway Development Company Limited (DBEDC) Guardian Enterprise Suite.

You assist project directors, QC managers, site engineers, O&M operators, and HR executives with instant insights, structured data queries, operations guidance, and form automations across the expressway project lifecycle.

CORE MISSION & CAPABILITIES:
1. QUALITY ASSURANCE & SITE EXECUTION (QC):
   - Non-Conformance Reports (NCRs), RFIs (Request for Information), Site Daily Works, Objections, inspection logs, and clearance tracking.
2. OPERATIONS & MAINTENANCE (O&M) & TRAFFIC MONITORING:
   - Traffic Monitoring Center (TMC/ITS), Toll Operations, Incident Management, Work Orders, Equipment status, and VMS messaging.
3. HUMAN RESOURCE & WORKFORCE MANAGEMENT (HRM):
   - Attendance logs, Biometric ADMS devices, Shifts, Rosters, Overtime, Leave Balances, Leave Requests, and Employee Directories.
4. FINANCIAL & ADMINISTRATIVE OPERATIONS:
   - Petty Cash ledgers, Expense vouchers, Loans, Reconciliations, Official Letters, Module Permissions, and System Diagnostics.

OPERATING RULES:
- ALWAYS check real data before answering factual questions using the `query_data` tool. Never guess or hallucinate statistics, counts, or entity IDs.
- For write actions (e.g., "Create an NCR", "Apply for casual leave", "Record a petty cash expense", "Report an incident"), use `prepare_operation` to generate a live, interactive, pre-filled form that posts to the actual secured endpoint.
- For user navigation (e.g., "Take me to Attendance", "Open O&M dashboard"), use the `navigate` tool to route them to the verified page.
- Present numeric distributions, comparisons, and time series clearly using the tool-generated Generative UI blocks (stats, sparklines, tables, donuts, and bars).
- Keep text responses clear, authoritative, concise, and professional.
PROMPT,

    /*
    |--------------------------------------------------------------------------
    | Agent Loop Execution Settings
    |--------------------------------------------------------------------------
    |
    | Aeon runs an unthrottled, multi-hop reasoning loop that executes until
    | completion or until an action/form is rendered for user confirmation.
    |
    */
    'agent' => [
        'max_loops' => (int) env('AEON_MAX_LOOPS', 25), // High capacity for multi-step reasoning
        'cycle_guard' => true, // Prevents identical repeated tool calls from looping
    ],

    /*
    |--------------------------------------------------------------------------
    | Budget & Token Safety Fuses
    |--------------------------------------------------------------------------
    */
    'budget' => [
        'daily_tokens_per_user' => (int) env('AEON_DAILY_TOKEN_LIMIT', 500000),
        'max_conversation_turns' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | Retrieval Augmented Generation (RAG) Settings
    |--------------------------------------------------------------------------
    */
    'rag' => [
        'enabled' => true,
        'similarity_threshold' => 0.40,
        'max_chunks' => 6,
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Providers Configuration
    |--------------------------------------------------------------------------
    */
    'providers' => [
        'gemini' => [
            'api_key' => env('GEMINI_API_KEY', env('AEON_GEMINI_KEY')),
            'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),
            // Tried in order when the primary is busy (429/503) or failing. Google retired 2.0 and 1.5 (404); keep these
            // on the primary's generation so tool calls replay the same way. Comma-separated in the env.
            'fallback_models' => env('GEMINI_FALLBACK_MODELS', 'gemini-2.5-flash-lite,gemini-2.5-pro'),
            // text-embedding-004 was retired (404 since early 2026); gemini-embedding-001 is its successor.
            'embed_model' => env('GEMINI_EMBED_MODEL', 'gemini-embedding-001'),
            // Requested vector size (768, 1536 or 3072). Changing it or the model needs `aeon:index --fresh`.
            'embed_dims' => (int) env('GEMINI_EMBED_DIMS', 768),
            'endpoint' => env('GEMINI_ENDPOINT', 'https://generativelanguage.googleapis.com/v1beta'),
            'temperature' => 0.3,
            'max_tokens' => 4096,
            'retries' => 3,
            'retry_base_ms' => 500,
            'timeout' => 45,
        ],

        'openai' => [
            'api_key' => env('OPENAI_API_KEY', env('AEON_OPENAI_KEY')),
            'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
            'embed_model' => env('OPENAI_EMBED_MODEL', 'text-embedding-3-small'),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'temperature' => 0.3,
            'max_tokens' => 4096,
            'timeout' => 45,
        ],
    ],
];
