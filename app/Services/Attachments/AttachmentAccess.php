<?php

namespace App\Services\Attachments;

use App\Models\User;
use App\Services\Access\DepartmentScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Who may open an application's documents (they can hold medical data): the applicant, the approvers in the chain, and
 * holders of the dedicated view permission inside their DepartmentScope. Links are signed and short-lived, and the
 * route checks access again when it is opened.
 */
class AttachmentAccess
{
    public function __construct(private readonly DepartmentScope $scope) {}

    public function canView(User $user, Model $application): bool
    {
        $owner = (string) $application->user_id;
        if ($owner === (string) $user->employee_id) {
            return true;
        }
        foreach ((array) $application->approval_chain as $level) {
            if ((string) ($level['approver_id'] ?? '') === (string) $user->employee_id) {
                return true;
            }
        }
        $holds = $application instanceof \App\Models\HRM\Leave ? $user->can('leaves.attachments.view') : $user->can('overtime.attachments.view');
        if (! $holds) {
            return false;
        }

        return $this->scope->isGlobal($user) || $this->scope->canManage($user, $owner);
    }

    public function link(Media $media, bool $api, int $minutes = 20): string
    {
        return URL::temporarySignedRoute($api ? 'api.v1.attachments.show' : 'attachments.show', now()->addMinutes($minutes), ['media' => $media->id]);
    }

    /**
     * What the application list/inbox shows for the documents and the re-upload state.
     *
     * @return array{files: list<array<string, mixed>>, history: list<array<string, mixed>>, returned_for_reupload: bool, reupload: array<string, mixed>|null}
     */
    public function present(Model $application, ?User $viewer, bool $api): array
    {
        $allowed = $viewer !== null && $this->canView($viewer, $application);
        $previews = $application->getMedia('attachment_previews');
        $files = $application->getMedia('attachments')->map(fn (Media $m) => [
            'id' => $m->id, 'name' => (string) ($m->getCustomProperty('original_name') ?: $m->file_name), 'mime_type' => $m->mime_type,
            'size' => $m->size, 'pages' => (int) $m->getCustomProperty('pages', 1),
            'uploaded_at' => $m->created_at?->toIso8601String(), 'superseded' => (bool) $m->getCustomProperty('superseded_at'),
            'url' => $allowed ? $this->link($m, $api) : null,
            // Page images of a scanned PDF (the mobile app shows these; it has no PDF viewer).
            'previews' => $allowed ? $previews->filter(fn (Media $p) => (int) $p->getCustomProperty('for') === $m->id)
                ->sortBy(fn (Media $p) => (int) $p->getCustomProperty('page'))->map(fn (Media $p) => $this->link($p, $api))->values()->all() : [],
        ])->values()->all();

        return [
            'files' => $files,
            'history' => $allowed ? $application->attachmentEvents()->orderBy('id')->get(['action', 'actor_id', 'reason', 'created_at'])->toArray() : [],
            'returned_for_reupload' => $application->isReturnedForReupload(),
            'reupload' => $application->reupload_requested_at ? [
                'reason' => $application->reupload_reason, 'requested_at' => $application->reupload_requested_at->toIso8601String(),
                'requested_by' => $application->reupload_requested_by, 'count' => (int) $application->reupload_count,
            ] : null,
        ];
    }
}
