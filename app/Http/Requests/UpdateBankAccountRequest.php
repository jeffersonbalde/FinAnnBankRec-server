<?php

namespace App\Http\Requests;

/**
 * Same rules as creation; the unique check ignores the current record via the
 * route-bound {bank_account}.
 */
class UpdateBankAccountRequest extends StoreBankAccountRequest {}
