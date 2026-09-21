<?php

namespace App\Services\Imports;

interface ImportParser
{
    /**
     * Read a stored file and return normalised rows + errors + header metadata.
     */
    public function parse(string $absolutePath): ParsedImport;
}
