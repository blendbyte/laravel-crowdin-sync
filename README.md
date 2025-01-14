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
php artisan vendor:publish --tag="laravel-crowdin-sync-config"
```

This is the contents of the published config file:

```php
return [
];
```

## Usage

```php
$laravelCrowdinSync = new Blendbyte\LaravelCrowdinSync();
echo $laravelCrowdinSync->echoPhrase('Hello, Blendbyte!');
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
