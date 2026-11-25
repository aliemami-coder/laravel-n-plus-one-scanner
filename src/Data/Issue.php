<?php

namespace NPlusOneScanner\Data;

final readonly class Issue
{
    public const SEVERITY_HIGH = 'high';

    public const SEVERITY_MEDIUM = 'medium';

    public const TYPE_LAZY_LOADING_IN_LOOP = 'lazy_loading_in_loop';

    public const TYPE_EAGER_LOAD_IN_LOOP = 'eager_load_in_loop';

    public const TYPE_QUERY_IN_LOOP = 'query_in_loop';

    public const TYPE_MISSING_WHEN_LOADED = 'missing_when_loaded';

    public const TYPE_BLADE_LAZY_LOADING = 'blade_lazy_loading';

    public function __construct(
        public string $file,
        public int $line,
        public string $type,
        public string $severity,
        public ?string $model,
        public ?string $relation,
        public string $message,
        public string $suggestion,
        public string $snippet = '',
    ) {}

    public function fingerprint(): string
    {
        return sha1(implode('|', [
            str_replace('\\', '/', $this->file),
            $this->type,
            $this->model ?? '',
            $this->relation ?? '',
            trim($this->snippet),
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'file' => $this->file,
            'line' => $this->line,
            'type' => $this->type,
            'severity' => $this->severity,
            'model' => $this->model,
            'relation' => $this->relation,
            'message' => $this->message,
            'suggestion' => $this->suggestion,
            'snippet' => $this->snippet,
            'fingerprint' => $this->fingerprint(),
        ];
    }
}
