<?php

// config for Blendbyte/LaravelCrowdinSync
return [
    // Crowdin Access Token for the API
    'api_key' => env('CROWDIN_API_KEY'),

    // Project ID for Translation Files (must be "File-based project")
    'project_id_files' => env('CROWDIN_PROJECT_ID_FILES', -1),

    // Project ID for Content Translations (must be "String-based project")
    'project_id_content' => env('CROWDIN_PROJECT_ID_CONTENT', -1),

    // File Update Option, choose one of clear_translations_and_approvals, keep_translations, keep_translations_and_approvals
    'file_update_options' => env('CROWDIN_FILE_UPDATE_OPTIONS', 'clear_translations_and_approvals'),

    // Only export approved translations for translation files
    'file_export_approved_only' => env('CROWDIN_FILE_EXPORT_APPROVED_ONLY', true),

    // Content branch ID
    'content_branch_id' => env('CROWDIN_CONTENT_BRANCH_ID', -1),

    // Only apply approved translations to content translations
    'content_approved_only' => env('CROWDIN_CONTENT_APPROVED_ONLY', false),
];
