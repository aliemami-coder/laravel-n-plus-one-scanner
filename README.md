# Laravel N+1 Static Scanner

A static AST analysis package for Laravel (`PHP 8.2+`, `Laravel 10 / 11 / 12`) that scans your entire project—without executing code or requiring a database connection—to detect and report N+1 query issues.

## Features

- **Model & Relationship Discovery**: Automatically parses Eloquent models (`app/Models`) to map relationships and `$with` defaults.
- **PHP Loop Analyzer**: Detects lazy-loaded relationship accesses (`$user->posts`) and relation query calls (`$user->posts()->count()`) inside `foreach`, `for`, `while`, and Collection callbacks (`each`, `map`, `filter`, `flatMap`, `transform`), while respecting `with()`, `load()`, `loadMissing()`, `$with`, and `relationLoaded()`.
- **Direct Query in Loop Analyzer**: Flags `Model::find()`, `Model::where()->first()`, `DB::table()->get()`, and `DB::select()` executed inside loops.
- **API Resource Analyzer**: Flags `$this->relation` accesses inside `JsonResource` / `ResourceCollection` that are not wrapped in `$this->whenLoaded()` or guarded by `$this->relationLoaded()`.
- **Blade View Analyzer**: Scans `@foreach` / `@forelse` blocks in `.blade.php` files and cross-references controller `view()` / `compact()` eager-loading context.
- **Suppression & Baseline**: Supports `// @nplusone-ignore` comments, `ignore_relations` config, and `--generate-baseline` / `--baseline` for gradual adoption.
- **CI/CD Ready**: Supports `--format=json`, `--severity=high|medium|all`, and non-zero exit codes.

## Installation

Add the package via Composer (or as a local `path` repository in `composer.json`):

```json
"repositories": [
    {
        "type": "path",
        "url": "../laravel-n-plus-one-scanner"
    }
]
```

```bash
composer require ali/laravel-n-plus-one-scanner --dev
php artisan vendor:publish --tag=nplusone-config
```

## Usage

```bash
# Scan the entire project (table output)
php artisan nplusone:scan

# Scan a specific path
php artisan nplusone:scan app/Http/Controllers

# JSON output for CI/CD
php artisan nplusone:scan --format=json

# HTML report (writes nplusone-report.html by default, or pass --output=path/to/report.html)
php artisan nplusone:scan --format=html
php artisan nplusone:scan --format=html --output=storage/app/nplusone-report.html

# Generate and use a baseline
php artisan nplusone:scan --generate-baseline
php artisan nplusone:scan --baseline=nplusone-baseline.json
```

### Inline Suppression

```php
foreach ($users as $user) {
    // @nplusone-ignore
    echo $user->profile->bio;
}
```
