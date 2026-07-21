<?php

namespace App\Services\DailyWork;

use App\Models\DailyWork;
use App\Models\RfiObjection;
use App\Models\User;
use App\Notifications\RfiObjectionNotification;
use App\Services\Project\DailyWorkService;
use App\Services\Realtime\RealtimeSignal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Single domain pipeline for the daily-work objection lifecycle.
 *
 * Every objection state transition — create, submit, start-review, resolve,
 * reject — funnels through this service so that the THREE side effects fire
 * exactly once, from ONE place, identically regardless of client (mobile API
 * or web):
 *
 *   1. the state transition (delegated to DailyWorkService / the model), which
 *      also writes the RfiObjectionStatusLog audit trail;
 *   2. the RfiObjectionNotification to the correct per-event recipients;
 *   3. the realtime marker touch('objection', 'all', actorId, action) that wakes
 *      the mobile "My Objections" / reviewer-queue screens and web dashboards.
 *
 * The recipient resolution mirrors the legacy web source of truth
 * (RfiObjectionController::notifyStakeholders) EXACTLY:
 *   - always: incharge + assigned users of every linked daily work;
 *   - on "submitted": also every manager (Super Admin / Admin / Project Manager
 *     / Consultant);
 *   - on "resolved" / "rejected": also the objection creator;
 *   - never the actor themselves (self-echo suppression).
 *
 * Start-review deliberately notifies nobody — matching the web controller,
 * which transitions to under_review without dispatching a notification.
 */
class ObjectionService
{
    public function __construct(
        private readonly DailyWorkService $dailyWorkService,
        private readonly RealtimeSignal $realtime,
    ) {}

    /**
     * Create a new objection for a daily work.
     *
     * Mirrors the web store(): a draft is silent, a directly-submitted
     * objection notifies the "submitted" stakeholders.
     */
    public function create(DailyWork $dailyWork, array $data, User $actor): RfiObjection
    {
        $objection = $this->dailyWorkService->storeObjection($dailyWork, $data, $actor);

        if ((string) $objection->status === RfiObjection::STATUS_SUBMITTED) {
            $this->notify($objection, RfiObjectionNotification::EVENT_SUBMITTED, (int) $actor->id);
        }

        $this->realtime->touch('objection', 'all', (int) $actor->id, 'created');

        return $objection;
    }

    /**
     * Submit a draft objection for review.
     */
    public function submit(RfiObjection $objection, User $actor): RfiObjection
    {
        $objection = $this->dailyWorkService->submitObjection($objection);

        $this->notify($objection, RfiObjectionNotification::EVENT_SUBMITTED, (int) $actor->id);
        $this->realtime->touch('objection', 'all', (int) $actor->id, 'submitted');

        return $objection;
    }

    /**
     * Move a submitted objection to under review. Notifies nobody (web parity).
     */
    public function startReview(RfiObjection $objection, User $actor): RfiObjection
    {
        $objection = $this->dailyWorkService->startReviewObjection($objection);

        $this->realtime->touch('objection', 'all', (int) $actor->id, 'review');

        return $objection;
    }

    /**
     * Resolve an objection and notify the creator + affected stakeholders.
     */
    public function resolve(RfiObjection $objection, ?string $resolutionNotes, User $actor): RfiObjection
    {
        $objection = $this->dailyWorkService->resolveObjection($objection, $resolutionNotes);

        $this->notify($objection, RfiObjectionNotification::EVENT_RESOLVED, (int) $actor->id);
        $this->realtime->touch('objection', 'all', (int) $actor->id, 'resolved');

        return $objection;
    }

    /**
     * Reject an objection and notify the creator + affected stakeholders.
     */
    public function reject(RfiObjection $objection, ?string $rejectionReason, User $actor): RfiObjection
    {
        $objection = $this->dailyWorkService->rejectObjection($objection, $rejectionReason);

        $this->notify($objection, RfiObjectionNotification::EVENT_REJECTED, (int) $actor->id);
        $this->realtime->touch('objection', 'all', (int) $actor->id, 'rejected');

        return $objection;
    }

    /**
     * Resolve the recipient set for an event and dispatch the notification.
     *
     * Recipient resolution is a 1:1 port of the legacy web
     * RfiObjectionController::notifyStakeholders(). Fail-open: a notification
     * failure is logged and swallowed so it can never break the write path.
     *
     * @param  int|null  $actorId  the acting user, excluded from the recipients
     */
    public function notify(RfiObjection $objection, string $event, ?int $actorId): void
    {
        try {
            $dailyWorks = $objection->dailyWorks()
                ->with(['inchargeUser', 'assignedUser'])
                ->get();

            /** @var Collection<int, User> $usersToNotify */
            $usersToNotify = collect();

            foreach ($dailyWorks as $dailyWork) {
                if ($dailyWork->incharge && $dailyWork->inchargeUser) {
                    $usersToNotify->push($dailyWork->inchargeUser);
                }

                if ($dailyWork->assigned && $dailyWork->assignedUser && $dailyWork->assigned !== $dailyWork->incharge) {
                    $usersToNotify->push($dailyWork->assignedUser);
                }
            }

            // Submitted objections escalate to managers/admins for triage.
            // Pin the role lookup to the 'web' guard (where this app's roles are
            // stored): the SAME service now runs under the mobile 'sanctum' guard,
            // and without an explicit guard Spatie would resolve to 'sanctum',
            // throw RoleDoesNotExist, and silently drop EVERY recipient.
            if ($event === RfiObjectionNotification::EVENT_SUBMITTED) {
                $managers = User::role(['Super Admin', 'Admin', 'Project Manager', 'Consultant'], 'web')
                    ->whereNull('deleted_at')
                    ->get();
                $usersToNotify = $usersToNotify->merge($managers);
            }

            // Terminal decisions loop the creator back in.
            if (in_array($event, [RfiObjectionNotification::EVENT_RESOLVED, RfiObjectionNotification::EVENT_REJECTED], true)
                && $objection->createdBy) {
                $usersToNotify->push($objection->createdBy);
            }

            $usersToNotify = $usersToNotify
                ->unique('id')
                ->filter(fn (User $user): bool => (int) $user->id !== (int) $actorId);

            foreach ($usersToNotify as $user) {
                $user->notify(new RfiObjectionNotification($objection, $event));
            }
        } catch (\Throwable $e) {
            Log::error('Failed to send objection notifications', [
                'objection_id' => $objection->id,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
