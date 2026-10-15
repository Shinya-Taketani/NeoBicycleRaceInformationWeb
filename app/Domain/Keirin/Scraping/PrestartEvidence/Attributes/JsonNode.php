<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\PrestartEvidence\Attributes;

use RuntimeException;

final readonly class JsonNode
{
    /** @param array<int|string, self> $children */
    public function __construct(
        public string $type,
        public string $lexeme,
        public int $offset,
        public string $pointer,
        public array $children = [],
    ) {}

    public function field(string $key): ?self
    {
        if ($this->type !== 'OBJECT') {
            throw new RuntimeException('JSON object required at structural path.');
        }

        return $this->children[$key] ?? null;
    }

    public function required(string $key): self
    {
        return $this->field($key) ?? throw new RuntimeException('Required JSON field missing.');
    }

    public function items(): array
    {
        if ($this->type !== 'ARRAY') {
            throw new RuntimeException('JSON list required at structural path.');
        }

        return $this->children;
    }

    public function value(): mixed
    {
        return match ($this->type) {
            'STRING' => json_decode($this->lexeme, true, 2, JSON_THROW_ON_ERROR),
            'NULL' => null,
            'BOOLEAN' => $this->lexeme === 'true',
            // Numbers and containers deliberately remain exact lexical strings.
            default => $this->lexeme,
        };
    }

    public function text(): string
    {
        if ($this->type !== 'STRING') {
            throw new RuntimeException('JSON string identity required.');
        }

        return $this->value();
    }

    public function digit(): int
    {
        $value = $this->value();
        if (! in_array($this->type, ['STRING', 'INTEGER'], true) || ! is_string($value)
            || preg_match('/^0*[1-9]$/D', $value) !== 1) {
            throw new RuntimeException('Bike/frame must be a string or integer in 1..9.');
        }

        return (int) $value;
    }
}
