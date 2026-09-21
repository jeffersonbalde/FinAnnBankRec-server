<?php

namespace App\Services\Imports;

use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Loads an .xlsx or .csv file into a plain, 1-indexed grid of string cells
 * so the parsers can work format-agnostically.
 */
class SpreadsheetReader
{
    /**
     * @return array<int, array<int, string>> row number => [col number => value]
     */
    public static function grid(string $path, ?string $sheetName = null): array
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($extension === 'csv') {
            return self::fromCsv($path);
        }

        $spreadsheet = IOFactory::load($path);
        $sheet = $sheetName !== null
            ? $spreadsheet->getSheetByName($sheetName)
            : $spreadsheet->getActiveSheet();

        if ($sheet === null) {
            $sheet = $spreadsheet->getSheet(0);
        }

        $grid = [];
        foreach ($sheet->toArray(null, true, false, false) as $r => $row) {
            $grid[$r + 1] = array_map(
                fn ($cell) => $cell === null ? '' : trim((string) $cell),
                array_values($row),
            );
            // shift to 1-indexed columns
            $grid[$r + 1] = array_combine(
                range(1, count($grid[$r + 1])),
                $grid[$r + 1],
            ) ?: [];
        }

        return $grid;
    }

    /**
     * @return array<int, array<int, string>>
     */
    private static function fromCsv(string $path): array
    {
        $grid = [];
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return [];
        }

        $r = 0;
        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $r++;
            $values = array_map(fn ($cell) => trim((string) ($cell ?? '')), $row);
            $grid[$r] = array_combine(range(1, count($values)), $values) ?: [];
        }

        fclose($handle);

        return $grid;
    }

    /**
     * Case-insensitive "does this row contain all these header labels".
     *
     * @param  array<int, string>  $row
     * @param  list<string>  $needles
     */
    public static function rowContains(array $row, array $needles): bool
    {
        $haystack = mb_strtolower(implode('|', $row));

        foreach ($needles as $needle) {
            if (! str_contains($haystack, mb_strtolower($needle))) {
                return false;
            }
        }

        return true;
    }
}
