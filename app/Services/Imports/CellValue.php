<?php

namespace App\Services\Imports;

use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Tolerant conversions for the messy values found in the client's Excel/CSV files.
 */
class CellValue
{
    public static function string(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Parse a peso amount: "10,000.00", "539000", "₱ 1,234.50", "(500)" (negative).
     */
    public static function decimal(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        $raw = trim((string) $value);
        $negative = str_starts_with($raw, '(') && str_ends_with($raw, ')');
        $clean = preg_replace('/[^0-9.\-]/', '', str_replace(',', '', $raw));

        if ($clean === '' || $clean === '-' || ! is_numeric($clean)) {
            return null;
        }

        $number = (float) $clean;

        return $negative ? -abs($number) : $number;
    }

    /**
     * Parse dates in the formats seen across the sample files, plus Excel serials.
     */
    public static function date(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value) && (float) $value > 20000 && (float) $value < 90000) {
            try {
                return CarbonImmutable::instance(ExcelDate::excelToDateTimeObject((float) $value));
            } catch (\Throwable) {
                // fall through to string parsing
            }
        }

        $raw = trim((string) $value);
        $raw = preg_replace('/\s+\d{1,2}:\d{2}(:\d{2})?$/', '', $raw) ?? $raw; // drop trailing time

        // Not remotely a date (e.g. a header label or a "TOTAL" line).
        if (! preg_match('/\d{2,}/', $raw)) {
            return null;
        }

        $formats = ['m/d/Y', 'n/j/Y', 'm-d-Y', 'm.d.Y', 'n.j.Y', 'Y-m-d', 'd/m/Y', 'M d, Y', 'F d, Y'];

        foreach ($formats as $format) {
            try {
                $parsed = CarbonImmutable::createFromFormat('!'.$format, $raw);
                if ($parsed !== false) {
                    return $parsed;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        try {
            return CarbonImmutable::parse($raw);
        } catch (\Throwable) {
            return null;
        }
    }
}
