# Laravel N+1 Static Scanner

A fast, zero-runtime static AST analysis package for Laravel (`PHP 8.2+`, `Laravel 10 / 11 / 12`) that scans your entire project—without executing code or requiring a database connection—to detect and report N+1 query issues.

## Features

- **Model, Trait & Inheritance Discovery**: Parses Eloquent models (`app/Models`), Traits, and `BaseModel` inheritance hierarchies to map relationships (including polymorphic `morphTo`), FQN targets, and `$with` defaults.
- **Data-Flow Variable Tracking**: Tracks variable reassignments, query-builder-to-collection transfers (`$query = User::with('posts'); $users = $query->get();`), variable aliases, and conditional branch intersections (`if` / `elseif` / `else`).
- **PHP Loop Analyzer**: Detects lazy-loaded relationship accesses (`$user->posts`) inside `foreach`, `for`, `while`, and Collection callbacks (`each`, `map`, `filter`, `flatMap`, `transform`), respecting `with()`, `load()`, `loadMissing()`, `$with`, and `relationLoaded()`.
- **Relationship Query Chain Analyzer**: Detects chained relationship queries inside loops (e.g. `$user->posts()->where('published', true)->count()`, `->exists()`, `->sum('amount')`, `->latest()->first()`) and suggests the exact aggregate or eager-loading fix (`withCount`, `withExists`, `withSum`, `withAvg`, `withMax`, `withMin`, `with`).
- **Direct Query in Loop Analyzer**: Flags `Model::find()`, `Model::where()->first()`, `DB::table()->get()`, and `DB::select()` executed inside loops.
- **API Resource Analyzer**: Flags `$this->relation` / `$this->resource->relation` accesses inside `JsonResource` / `ResourceCollection` without `whenLoaded()`, with scoped `relationLoaded()` guard analysis across `if`, ternary, `&&`, and early-return blocks.
- **Blade View Analyzer**: Scans `@foreach` / `@forelse` blocks in `.blade.php` files and cross-references controller `view()` / `compact()` call sites using safe intersection across multiple controllers.
- **Shared AST Cache**: Parses each PHP file at most once across discovery and all analyzers for fast scanning on large codebases.
- **Reports, Suppression & CI/CD**: Terminal table, JSON (with exit codes), and interactive searchable HTML reports (`--format=html`), plus `// @nplusone-ignore`, `ignore_relations`, and `--generate-baseline` / `--baseline`.

## Installation

```bash
composer require aliemami-coder/laravel-n-plus-one-scanner --dev
php artisan vendor:publish --tag=nplusone-config
```

## Usage

```bash
# Scan the entire project (colored terminal table output)
php artisan nplusone:scan

# Scan a specific path
php artisan nplusone:scan app/Http/Controllers

# Filter by severity (all, high, medium)
php artisan nplusone:scan --severity=high

# JSON output for CI/CD
php artisan nplusone:scan --format=json

# Interactive HTML report (writes nplusone-report.html by default, or pass --output=...)
php artisan nplusone:scan --format=html
php artisan nplusone:scan --format=html --output=storage/app/nplusone-report.html

# Generate and use a baseline for gradual adoption
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

## Contributing

Contributions are warmly welcome! Whether you want to report a bug, suggest a new Eloquent/Laravel pattern to detect, improve static analysis accuracy, or add tests and documentation, your help makes this tool better for the Laravel community.

1. Fork the repository
2. Create your feature branch (`git checkout -b feat/my-improvement`)
3. Run code formatting and tests:
   ```bash
   vendor/bin/pint --test
   vendor/bin/phpunit
   ```
4. Commit your changes and open a Pull Request

## License

This package is open-source software licensed under the [MIT License](LICENSE).
