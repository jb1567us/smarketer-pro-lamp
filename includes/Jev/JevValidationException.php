<?php

declare(strict_types=1);

namespace App\Jev;

/**
 * 422 — malformed request (bad question schema, state over budget, ...).
 *
 * Own file for PSR-4 autoloading; see JevAuthException.php for why the
 * subclasses no longer share JevException.php.
 */
class JevValidationException extends JevException
{
}
