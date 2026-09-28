<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\OutreachException;
use App\SequenceManager;

/**
 * LaunchCampaignAction — Phase 4 "Launch campaign".
 *
 * Expands a campaign's eligible leads into per-lead, per-step queued sends:
 * one sequence_enrollments row per lead, one step-1 `SequenceSend` task_queue
 * row per enrollment, honoring templates.step_order. The cron worker
 * (cron/process_queue.php) then executes each step through the existing
 * throttle/monitor system, and SendSequenceStepAction progresses step N to
 * step N+1 per the template's delay_days cadence.
 *
 * Deliberately NOT an ActionInterface lead-action: launch and stop are
 * campaign-scoped operations (driven from api/campaigns.php?action=launch),
 * which execute(int $leadId) cannot express.
 */
class LaunchCampaignAction
{
    public function __construct(private \App\PDO $pdo) {}

    /**
     * Launch the campaign.
     *
     * @return array{enrolled:int, queued:int, skipped_enrolled:int, skipped_suppressed:int, skipped_status:int, templates:int}
     * @throws OutreachException when the campaign is missing, inactive,
     *                           paused, has no templates, or the Phase-4
     *                           tables are not installed.
     */
    public function launch(int $campaignId): array
    {
        return SequenceManager::launch($this->pdo, $campaignId);
    }

    /**
     * Halt every active sequence of the campaign (manual stop).
     *
     * @return int number of enrollments stopped
     */
    public function stop(int $campaignId, string $reason = 'manual stop'): int
    {
        return SequenceManager::stopCampaign($this->pdo, $campaignId, $reason);
    }
}
