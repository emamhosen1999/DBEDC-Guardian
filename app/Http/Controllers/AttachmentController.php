<?php

namespace App\Http\Controllers;

use App\Models\Attachments\StagedAttachment;
use App\Models\HRM\Leave;
use App\Models\HRM\OvertimeRequest;
use App\Models\HRM\ReplacementLeaveRequest;
use App\Services\Attachments\AttachmentAccess;
use App\Services\Attachments\AttachmentRejected;
use App\Services\Attachments\AttachmentReuploadService;
use App\Services\Attachments\AttachmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Shared by the web (session) and the mobile API (Sanctum): upload and scan documents, view them through signed links,
 * and the "request re-upload" / "re-upload" actions. The server is authoritative for every check.
 */
class AttachmentController extends Controller
{
    public function __construct(
        private readonly AttachmentService $attachments,
        private readonly AttachmentAccess $access,
        private readonly AttachmentReuploadService $reupload,
    ) {}

    /** Upload a file (PDF or image) and run the clarity check. A file that is not clear is rejected with the reason. */
    public function stage(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file']]);
        $row = $this->attachments->stageUpload($request->file('file'), (string) $request->user()->employee_id);

        return response()->json(['success' => true, 'data' => $this->staged($row, $request)], 201);
    }

    /** "Scan document": one photographed page, flattened and cleaned. Returns the processed preview and the verdict (never rejects a blurry page: the app offers Retake). */
    public function scan(Request $request): JsonResponse
    {
        $request->validate(['photo' => ['required', 'file'], 'mode' => ['nullable', 'in:color,grey,bw']]);
        $row = $this->attachments->scanPage($request->file('photo'), (string) $request->user()->employee_id, (string) $request->input('mode', 'color'));

        return response()->json(['success' => true, 'data' => $this->staged($row, $request)], 201);
    }

    /** "Adjust corners" / colour mode: re-flatten the original photo. */
    public function adjust(Request $request, string $token): JsonResponse
    {
        $data = $request->validate([
            'corners' => ['nullable', 'array', 'size:4'], 'corners.*' => ['array', 'size:2'], 'corners.*.*' => ['numeric'],
            'mode' => ['nullable', 'in:color,grey,bw'],
        ]);
        $row = $this->mine($request, $token, StagedAttachment::KIND_SCAN_PAGE);
        $row = $this->attachments->rescan($row, $data['corners'] ?? null, $data['mode'] ?? null);

        return response()->json(['success' => true, 'data' => $this->staged($row, $request)]);
    }

    /** "Done": the pages become ONE PDF attachment. */
    public function finalize(Request $request): JsonResponse
    {
        $data = $request->validate(['pages' => ['required', 'array', 'min:1'], 'pages.*' => ['string']]);
        $row = $this->attachments->finalizeScan($data['pages'], (string) $request->user()->employee_id);

        return response()->json(['success' => true, 'data' => $this->staged($row, $request)], 201);
    }

    public function discard(Request $request, string $token): JsonResponse
    {
        $row = StagedAttachment::where('user_id', (string) $request->user()->employee_id)->where('token', $token)->whereNull('attached_at')->first();
        if ($row) {
            StagedAttachment::disk()->delete(array_filter([$row->path, $row->original_path]));
            $row->delete();
        }

        return response()->json(['success' => true]);
    }

    /** The staged preview (processed page) or the untouched original, for the owner only. */
    public function stagedImage(Request $request, string $token, string $which = 'processed'): StreamedResponse
    {
        $row = $this->mine($request, $token);
        $path = $which === 'original' ? $row->original_path : $row->path;
        abort_unless($path && StagedAttachment::disk()->exists($path), 404);

        return StagedAttachment::disk()->response($path, null, $this->safeHeaders($row->mime));
    }

    /** A stored document: signed link + login + access rule. */
    public function show(Request $request, Media $media): StreamedResponse
    {
        $application = $media->model;
        abort_unless($application && $this->access->canView($request->user(), $application), 403);

        $disk = Storage::disk($media->disk);
        abort_unless($disk->exists($media->getPathRelativeToRoot()), 404);

        return $disk->response($media->getPathRelativeToRoot(), $media->file_name, $this->safeHeaders($media->mime_type));
    }

    /** Approver: "Request re-upload" with a mandatory reason. */
    public function requestReupload(Request $request, string $type, int $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $application = $this->application($type, $id);
        $this->reupload->request($application, $request->user(), $data['reason']);

        return response()->json(['success' => true, 'message' => 'Returned to the employee for a clearer document.', 'data' => $this->access->present($application->refresh(), $request->user(), $this->isApi($request))]);
    }

    /** Employee: upload the new document; the application goes back to the same approval step. */
    public function submitReupload(Request $request, string $type, int $id): JsonResponse
    {
        $data = $request->validate(['attachment_tokens' => ['required', 'array', 'min:1', 'max:'.config('attachments.limits.max_files')], 'attachment_tokens.*' => ['string']]);
        $application = $this->application($type, $id);
        $this->reupload->reupload($application, $request->user(), $data['attachment_tokens']);

        return response()->json(['success' => true, 'message' => 'Thank you. The new document was sent to your approver.', 'data' => $this->access->present($application->refresh(), $request->user(), $this->isApi($request))]);
    }

    private function application(string $type, int $id)
    {
        return match ($type) {
            'leave' => Leave::findOrFail($id),
            'overtime' => OvertimeRequest::findOrFail($id),
            'replacement' => ReplacementLeaveRequest::findOrFail($id),
            default => abort(404),
        };
    }

    private function mine(Request $request, string $token, ?string $kind = null): StagedAttachment
    {
        $q = StagedAttachment::usableBy((string) $request->user()->employee_id)->where('token', $token);
        if ($kind) {
            $q->where('kind', $kind);
        }

        return $q->firstOr(fn () => throw new AttachmentRejected(__('attachments.errors.staged_missing'), 'staged_missing'));
    }

    private function isApi(Request $request): bool
    {
        return $request->is('api/*');
    }

    /** @return array<string, mixed> */
    private function staged(StagedAttachment $row, Request $request): array
    {
        $api = $this->isApi($request);
        $isPage = $row->kind === StagedAttachment::KIND_SCAN_PAGE;

        return [
            'token' => $row->token, 'kind' => $row->kind, 'mime_type' => $row->mime, 'name' => $row->original_name, 'size' => $row->size,
            'pages' => $row->page_count, 'mode' => $row->mode, 'corners' => $row->corners, 'confidence' => $row->confidence,
            'needs_adjust' => $isPage && ($row->confidence ?? 0) < (float) config('attachments.scanner.min_confidence'),
            'clarity' => $row->clarity, 'passed' => $row->clarity_passed, 'expires_at' => $row->expires_at?->toIso8601String(),
            'preview_url' => $row->mime === 'application/pdf' ? null : route($api ? 'api.v1.attachments.staged.image' : 'attachments.staged.image', ['token' => $row->token, 'which' => 'processed'], $api),
            'original_url' => $isPage ? route($api ? 'api.v1.attachments.staged.image' : 'attachments.staged.image', ['token' => $row->token, 'which' => 'original'], $api) : null,
        ];
    }

    /** Documents are never rendered as active content. */
    private function safeHeaders(string $mime): array
    {
        return [
            'Content-Type' => $mime, 'Content-Disposition' => 'inline', 'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store', 'Content-Security-Policy' => "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox",
        ];
    }
}
