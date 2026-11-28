<?php

namespace App\Services\Sports\Exceptions;

/** The full owning operation must retry; a stale enclosing transaction must not continue. */
class SportEventIdentityRetry extends SportEventIdentityConflict {}
