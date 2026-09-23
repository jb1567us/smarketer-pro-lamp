<?php

declare(strict_types=1);

namespace App\Jev;

/**
 * 401 — bad or missing API key.
 *
 * Lives in its own file so the PSR-4 autoloader (includes/autoload.php)
 * can resolve App\Jev\JevAuthException on a fresh request. Previously this
 * class shared JevException.php, so any throw/catch of it before
 * JevException.php was loaded produced "Class not found" instead of the
 * intended typed exception.
 */
class JevAuthException extends JevException
{
}
