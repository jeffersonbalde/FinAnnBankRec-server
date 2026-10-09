<?php

namespace App\Http\Requests\Concerns;

trait NormalizesAmounts
{
    /** The largest figure the app accepts (13 digits) — well inside the DECIMAL(18,2) columns. */
    public const MAX_AMOUNT = '9999999999999.99';

    /**
     * Accept amounts typed with thousands separators or a currency sign
     * ("₱1,234.50") by reducing them to a plain number before validation.
     *
     * @param  list<string>  $keys
     */
    protected function normalizeAmounts(array $keys): void
    {
        $clean = [];

        foreach ($keys as $key) {
            $value = $this->input($key);

            if (is_string($value)) {
                $stripped = preg_replace('/[\s,₱]/u', '', $value);
                $clean[$key] = $stripped === '' ? null : $stripped;
            }
        }

        if ($clean !== []) {
            $this->merge($clean);
        }
    }
}
