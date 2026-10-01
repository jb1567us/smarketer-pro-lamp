<?php

declare(strict_types=1);

namespace App\Actions;

/**
 * Optional contract for actions that want the exact task_queue row they are
 * executing. TaskProcessor injects the claimed task ID before calling
 * execute(). Without it, actions can only look up their task by lead_id,
 * which is ambiguous when a lead has several In Progress tasks (e.g. two
 * campaigns each sending a sequence step concurrently) — the action could
 * process a different row than the one the worker claimed, stranding the
 * claimed row In Progress while completing the wrong one.
 */
interface TaskAware
{
    public function setTaskId(int $taskId): void;
}
