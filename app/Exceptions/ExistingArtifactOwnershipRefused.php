<?php

namespace App\Exceptions;

/** A failed current-owner/policy admission must not be treated as permission to invalidate a row. */
final class ExistingArtifactOwnershipRefused extends \RuntimeException {}
