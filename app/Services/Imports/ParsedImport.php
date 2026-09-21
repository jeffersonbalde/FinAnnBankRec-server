<?php

namespace App\Services\Imports;

/**
 * Result of parsing an uploaded import file: normalised rows ready for preview,
 * per-row errors, and header metadata pulled from the file.
 */
class ParsedImport
{
    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array{row: int, message: string}>  $errors
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public array $rows = [],
        public array $errors = [],
        public array $meta = [],
    ) {}

    public function rowCount(): int
    {
        return count($this->rows) + count($this->errors);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'rows' => $this->rows,
            'errors' => $this->errors,
            'meta' => $this->meta,
            'row_count' => $this->rowCount(),
            'valid_count' => count($this->rows),
            'error_count' => count($this->errors),
        ];
    }
}
