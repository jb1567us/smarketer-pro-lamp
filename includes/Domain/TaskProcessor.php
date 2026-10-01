<?php

declare(strict_types=1);

namespace App\Domain;

use App\Actions\ActionInterface;
use App\Actions\QualifyLeadAction;
use App\Actions\EnrichLeadAction;
use App\Actions\DraftOutreachAction;
use App\Actions\SendSequenceStepAction;
use App\Database;
use App\Exceptions\OutreachException;
use App\Routers\SmartLLMRouter;
use SplQueue;

class TaskProcessor
{
    private \App\PDO $pdo;
    private SmartLLMRouter $llmRouter;
    
    public function __construct(\App\PDO $pdo, SmartLLMRouter $llmRouter)
    {
        $this->pdo = $pdo;
        $this->llmRouter = $llmRouter;
    }

    /**
     * Process a single task by ID.
     *
     * The claim is atomic: only the caller that flips the row from
     * 'Pending' to 'In Progress' proceeds. Overlapping cron runs (or a
     * manual trigger racing the cron) can never process the same task
     * twice -- losers get rowCount 0 and return silently.
     */
    public function processTask(int $taskId): void
    {
        $claim = $this->pdo->prepare(
            "UPDATE task_queue SET status = 'In Progress', processed_at = NOW(), retry_count = retry_count + 1 " .
            "WHERE id = ? AND status = 'Pending'"
        );
        $claim->execute([$taskId]);

        if ($claim->rowCount() === 0) {
            return; // Already claimed by another run, or no longer pending.
        }

        $stmt = $this->pdo->prepare("SELECT * FROM task_queue WHERE id = ?");
        $stmt->execute([$taskId]);
        $task = $stmt->fetch(\App\PDO::FETCH_ASSOC);

        if (!$task) {
            return;
        }

        try {
            // ITEM2 - bulk-verify jobs are queue-backed batch jobs, not
            // per-lead actions: they manage their own cursor in the task
            // payload and park themselves back to 'Pending' between batches.
            if ($task['task_type'] === \App\BulkVerifyJob::TASK_TYPE) {
                \App\BulkVerifyJob::run($this->pdo, $taskId);
                return;
            }

            $action = $this->getActionFactory($task['task_type']);
            
            if (!$action) {
                throw new OutreachException("Unknown or unsupported task type: " . $task['task_type']);
            }
            
            if ($action instanceof \App\Actions\TaskAware) { $action->setTaskId((int)$task["id"]); }
            $result = $action->execute((int)$task["lead_id"]);

            if ($result) {
                $this->updateTaskStatus($taskId, 'Completed');
            } else {
                $this->updateTaskStatus($taskId, 'Failed', 'Processing returned false');
            }

        } catch (\Exception $e) {
            $this->updateTaskStatus($taskId, 'Failed', $e->getMessage());
        }
    }

    /**
     * Process multiple tasks using SPL Queue for memory efficiency.
     */
    public function processQueue(array $taskIds): void
    {
        $queue = new SplQueue();
        foreach ($taskIds as $id) {
            $queue->enqueue($id);
        }

        while (!$queue->isEmpty()) {
            $taskId = $queue->dequeue();
            $this->processTask($taskId);
        }
    }

    private function getActionFactory(string $taskType): ?ActionInterface
    {
        return match ($taskType) {
            'Qualify' => new QualifyLeadAction($this->pdo, $this->llmRouter),
            'Enrich' => new EnrichLeadAction($this->pdo, $this->llmRouter),
            'Draft' => new DraftOutreachAction($this->pdo, $this->llmRouter),
            // Phase 4: per-step sequence sends for launched campaigns.
            'SequenceSend' => new SendSequenceStepAction($this->pdo, $this->llmRouter),
            // Legacy aliases (pre-Phase-0 rows may still carry these — the
            // queue ENUM itself allows them). Map, don't fail.
            'Qualification' => new QualifyLeadAction($this->pdo, $this->llmRouter),
            'Enrichment' => new EnrichLeadAction($this->pdo, $this->llmRouter),
            'Drafting' => new DraftOutreachAction($this->pdo, $this->llmRouter),
            default => null,
        };
    }

    private function updateTaskStatus(int $id, string $status, ?string $error = null): void
    {
        // Guarded write: only the In Progress claim owner may write. An action that re-queued its own task (transient retry) keeps that status.
        $stmt = $this->pdo->prepare("UPDATE task_queue SET status = ?, error_message = ?, processed_at = NOW() WHERE id = ? AND status = 'In Progress'");
        $stmt->execute([$status, $error, $id]);
    }
}
