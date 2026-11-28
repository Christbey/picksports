<?php

namespace App\Exceptions;

/** A refused score write must not become an updated result or be swallowed as invalid provider input. */
final class MlbScoreReconciliationPersistenceException extends \RuntimeException {}
