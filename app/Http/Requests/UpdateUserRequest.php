<?php

namespace App\Http\Requests;

/**
 * Same rules as creation; email uniqueness ignores the route-bound {user} and
 * the password becomes optional.
 */
class UpdateUserRequest extends StoreUserRequest {}
