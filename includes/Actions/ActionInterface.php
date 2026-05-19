<?php

declare(strict_types=1);

namespace App\Actions;

interface ActionInterface
{
    /**
     * Executes the specific action on a given lead.
     *
     * @param int $leadId
     * @return bool True if successful, false otherwise.
     */
    public function execute(int $leadId): bool;
}
