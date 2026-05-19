<?php

declare(strict_types=1);

namespace App\Domain;

use App\Actions\ActionInterface;
use App\Actions\QualifyLeadAction;
use App\Actions\EnrichLeadAction;
use App\Actions\DraftOutreachAction;
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
     */
    public function processTask(int $taskId): void
    {
        $stmt = $this->pdo->prepare("SELECT * FROM task_queue WHERE id = ?");
        $stmt->execute([$taskId]);
        $task = $stmt->fetch(\App\PDO::FETCH_ASSOC);

        if (!$task) {
            return;
        }

        $this->updateTaskStatus($taskId, 'In Progress');

        try {
            $action = $this->getActionFactory($task['task_type']);
            
            if (!$action) {
                throw new OutreachException("Unknown or unsupported task type: " . $task['task_type']);
            }
            
            $result = $action->execute((int)$task['lead_id']);

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
            default => null,
        };
    }

    private function updateTaskStatus(int $id, string $status, ?string $error = null): void
    {
        $stmt = $this->pdo->prepare("UPDATE task_queue SET status = ?, error_message = ?, processed_at = NOW() WHERE id = ?");
        $stmt->execute([$status, $error, $id]);
    }
}
