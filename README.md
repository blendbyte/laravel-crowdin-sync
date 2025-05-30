# Syncs Translation Data from Laravel to Crowdin

- Automatically syncs your translations files as source to Crowdin and also syncs back translations from Crowdin into your files
- Can sync custom translatable content (using spatie/laravel-translatable fields) to Crowdin and back into your database
- Can run as part of a scheduled command to ensure always up2date translations in your database

## Installation

You can install the package via composer:

```bash
composer require blendbyte/laravel-crowdin-sync
```

You can publish the config file with:

```bash
php artisan vendor:publish --tag="crowdin-sync-config"
```

These are the contents of the published default config file:

```php
return [
    // Debug Output to STDOUT
    'debug' => env('CROWDIN_DEBUG', false),

    // Crowdin Access Token for the API
    'api_key' => env('CROWDIN_API_KEY'),


    // Project ID for Translation Files (must be "File-based project")
    'project_id_files' => env('CROWDIN_PROJECT_ID_FILES', -1),

    // File Update Option, choose one of clear_translations_and_approvals, keep_translations, keep_translations_and_approvals
    'file_update_options' => env('CROWDIN_FILE_UPDATE_OPTIONS', 'clear_translations_and_approvals'),

    // Only export approved translations for translation files
    'file_export_approved_only' => env('CROWDIN_FILE_EXPORT_APPROVED_ONLY', true),


    // Project ID for Content Translations (must be "String-based project")
    'project_id_content' => env('CROWDIN_PROJECT_ID_CONTENT', -1),

    // Content branch ID
    'content_branch_id' => env('CROWDIN_CONTENT_BRANCH_ID', -1),

    // Only apply approved translations to content translations
    'content_approved_only' => env('CROWDIN_CONTENT_APPROVED_ONLY', false),
];
```

## Usage

```php
LaravelCrowdinSync::make()->syncFiles(source_path: 'lang/', crowdin_path: 'laravel/');
LaravelCrowdinSync::make()->uploadFiles(source_path: 'lang/', crowdin_path: 'laravel/');
LaravelCrowdinSync::make()->downloadFiles(source_path: 'lang/', crowdin_path: 'laravel/');

# refactoring this:
# LaravelCrowdinSync::make()->syncContent(\App\Models\Page::class);
# LaravelCrowdinSync::make()->uploadContent(\App\Models\Page::class);
# LaravelCrowdinSync::make()->downloadContent(\App\Models\Page::class);
```

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Blendbyte Inc.](https://github.com/blendbyte)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
