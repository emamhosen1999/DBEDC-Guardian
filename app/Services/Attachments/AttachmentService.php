<?php

namespace App\Services\Attachments;

use App\Models\Attachments\AttachmentEvent;
use App\Models\Attachments\StagedAttachment;
use App\Services\Attachments\Contracts\PdfRasterizer;
use App\Services\Attachments\Scanner\DocumentScanner;
use App\Services\Attachments\Scanner\ImagePdfBuilder;
use GdImage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * THE attachment pipeline, shared by the web and the mobile API.
 *
 * stage*(): accept a file (content-sniffed type, size, pages), re-encode images (drops EXIF incl. GPS and any payload),
 * run the clarity check, keep it on the private disk for 24 h and hand back a token.
 * scan*(): the server-assisted scanner (corners, flatten, enhance, multi-page PDF).
 * attach() / replace(): move staged files into the application's media (private disk, history kept).
 */
class AttachmentService
{
    public function __construct(
        private readonly AttachmentClarityService $clarity,
        private readonly DocumentScanner $scanner,
        private readonly PdfRasterizer $pdf,
    ) {}

    // ---------------------------------------------------------------- staging

    /** Stage an uploaded file (web or mobile). A file that is not clear is rejected with the specific guidance. */
    public function stageUpload(UploadedFile $file, string $userId): StagedAttachment
    {
        $bytes = $this->readUpload($file);
        $mime = $this->sniffMime($file->getRealPath());

        if ($mime === 'application/pdf') {
            return $this->stagePdf($file, $bytes, $userId);
        }

        $image = $this->decodeImage($file->getRealPath(), $bytes, $mime);
        $jpeg = $this->encodeJpeg($image, 90);
        $result = $this->clarity->checkImage($image);
        if (! $result->passed) {
            throw new AttachmentRejected($result->message, $result->code, $result->toArray());
        }

        return $this->store($userId, StagedAttachment::KIND_UPLOAD, $jpeg, 'image/jpeg', $file->getClientOriginalName(), $result);
    }

    private function stagePdf(UploadedFile $file, string $bytes, string $userId): StagedAttachment
    {
        if (! str_starts_with($bytes, '%PDF-')) {
            throw new AttachmentRejected(__('attachments.errors.unsupported_type'), 'unsupported_type');
        }
        // Active content has no place in a supporting document.
        if (preg_match('#/(JavaScript|JS|Launch|EmbeddedFile|OpenAction|AA)\b#', $bytes)) {
            throw new AttachmentRejected(__('attachments.errors.unsupported_type'), 'active_content');
        }
        $pages = $this->pdf->pageCount($file->getRealPath());
        $max = (int) config('attachments.limits.max_pdf_pages');
        if ($pages > $max) {
            throw new AttachmentRejected(__('attachments.errors.too_many_pages', ['max' => $max]), 'too_many_pages');
        }

        $result = $this->clarity->checkPdf($file->getRealPath());
        if (! $result->passed) {
            throw new AttachmentRejected($result->message, $result->code, $result->toArray());
        }

        return $this->store($userId, StagedAttachment::KIND_UPLOAD, $bytes, 'application/pdf', $file->getClientOriginalName(), $result, $pages);
    }

    // ---------------------------------------------------------------- scanner

    /** "Scan document": one photographed page. Always returns a processed preview; the verdict says whether it is clear. */
    public function scanPage(UploadedFile $photo, string $userId, string $mode = 'color'): StagedAttachment
    {
        $bytes = $this->readUpload($photo);
        $mime = $this->sniffMime($photo->getRealPath());
        if ($mime === 'application/pdf') {
            throw new AttachmentRejected(__('attachments.errors.unsupported_type'), 'unsupported_type');
        }
        $image = $this->decodeImage($photo->getRealPath(), $bytes, $mime);
        $original = $this->encodeJpeg($image, 90);

        $detected = $this->scanner->detect($image);
        $mode = $this->validMode($mode);
        $row = $this->process($userId, $image, $original, $detected['corners'], $mode, $detected['confidence'], $photo->getClientOriginalName());

        return $row;
    }

    /** "Adjust corners" / mode change: re-flatten the ORIGINAL photo with the user's corners. */
    public function rescan(StagedAttachment $page, ?array $corners, ?string $mode): StagedAttachment
    {
        $original = StagedAttachment::disk()->get((string) $page->original_path);
        $image = @imagecreatefromstring((string) $original);
        if (! $image) {
            throw new AttachmentRejected(__('attachments.clarity.unreadable'), 'unreadable');
        }
        $corners = $corners ?: $page->corners;
        $mode = $this->validMode($mode ?? $page->mode ?? 'color');
        // User-placed corners are trusted completely.
        $confidence = $corners === $page->corners ? $page->confidence : 1.0;

        $old = [$page->path];
        $updated = $this->process($page->user_id, $image, (string) $original, $corners, $mode, (float) $confidence, $page->original_name, $page);
        StagedAttachment::disk()->delete(array_filter($old, fn ($p) => $p !== $updated->path));

        return $updated;
    }

    /**
     * "Done": combine the processed pages (in order) into ONE PDF. Nothing unclear can be combined.
     *
     * @param  list<string>  $tokens
     */
    public function finalizeScan(array $tokens, string $userId): StagedAttachment
    {
        $max = (int) config('attachments.limits.max_scan_pages');
        if ($tokens === [] || count($tokens) > $max) {
            throw new AttachmentRejected(__('attachments.errors.too_many_pages', ['max' => $max]), 'too_many_pages');
        }
        $pages = collect($tokens)->map(function ($t) use ($userId) {
            $p = StagedAttachment::usableBy($userId)->where('token', $t)->where('kind', StagedAttachment::KIND_SCAN_PAGE)->first();
            if (! $p) {
                throw new AttachmentRejected(__('attachments.errors.staged_missing'), 'staged_missing');
            }
            if (! $p->clarity_passed) {
                throw new AttachmentRejected((string) ($p->clarity['message'] ?? __('attachments.errors.staged_unclear')), 'staged_unclear', $p->clarity);
            }

            return $p;
        });

        $files = $pages->map(fn (StagedAttachment $p) => $p->absolutePath())->all();
        $pdf = ImagePdfBuilder::build($files);

        $row = $this->store($userId, StagedAttachment::KIND_SCAN_PDF, $pdf, 'application/pdf', 'scan.pdf', ClarityResult::pass(['pdf' => 'scan', 'pages' => count($files)]), count($files));
        // Keep the page images: the pages are consumed below, and the app shows documents as images.
        $previews = [];
        foreach ($files as $i => $file) {
            $path = config('attachments.staging_dir').'/'.Str::uuid().'-p'.($i + 1).'.jpg';
            StagedAttachment::disk()->put($path, (string) file_get_contents($file));
            $previews[] = $path;
        }
        $row->update(['preview_paths' => $previews]);
        $pages->each(fn (StagedAttachment $p) => $p->update(['attached_at' => now()]));

        return $row;
    }

    /** @param list<array{0: float, 1: float}> $corners */
    private function process(string $userId, GdImage $image, string $originalJpeg, array $corners, string $mode, float $confidence, ?string $name, ?StagedAttachment $into = null): StagedAttachment
    {
        try {
            $flat = $this->scanner->flatten($image, $corners, $mode);
        } catch (\Throwable) {
            throw new AttachmentRejected(__('attachments.clarity.unreadable'), 'unreadable');
        }
        $result = $this->clarity->checkImage($flat);
        $processed = $this->encodeJpeg($flat, (int) config('attachments.scanner.jpeg_quality'));

        $disk = StagedAttachment::disk();
        $dir = config('attachments.staging_dir');
        $path = $dir.'/'.Str::uuid().'.jpg';
        $disk->put($path, $processed);
        $originalPath = $into?->original_path ?? $dir.'/'.Str::uuid().'-original.jpg';
        if ($into === null) {
            $disk->put($originalPath, $originalJpeg);
        }

        $attrs = [
            'path' => $path, 'original_path' => $originalPath, 'mime' => 'image/jpeg', 'size' => strlen($processed),
            'mode' => $mode, 'corners' => array_map(fn ($c) => [round($c[0], 1), round($c[1], 1)], $corners),
            'confidence' => round($confidence, 3), 'clarity' => $result->toArray(), 'clarity_passed' => $result->passed,
        ];

        if ($into) {
            $into->update($attrs);

            return $into->refresh();
        }

        return StagedAttachment::create($attrs + [
            'token' => (string) Str::uuid(), 'user_id' => $userId, 'kind' => StagedAttachment::KIND_SCAN_PAGE,
            'original_name' => $name !== null ? Str::limit($name, 200, '') : null, 'page_count' => 1,
            'expires_at' => now()->addHours((int) config('attachments.staging_ttl_hours')),
        ]);
    }

    // ---------------------------------------------------------------- attaching

    /**
     * The staged documents an application is being submitted with: must be the user's own, unexpired and clear.
     *
     * @param  list<string>  $tokens
     * @return Collection<int, StagedAttachment>
     */
    public function resolve(array $tokens, string $userId): Collection
    {
        $tokens = array_values(array_unique(array_filter($tokens)));
        $max = (int) config('attachments.limits.max_files');
        if (count($tokens) > $max) {
            throw new AttachmentRejected(__('attachments.errors.too_many_files', ['max' => $max]), 'too_many_files');
        }

        return collect($tokens)->map(function ($token) use ($userId) {
            $row = StagedAttachment::usableBy($userId)->where('token', $token)->where('kind', '!=', StagedAttachment::KIND_SCAN_PAGE)->first();
            if (! $row) {
                throw new AttachmentRejected(__('attachments.errors.staged_missing'), 'staged_missing');
            }
            if (! $row->clarity_passed) {
                throw new AttachmentRejected((string) ($row->clarity['message'] ?? __('attachments.errors.staged_unclear')), 'staged_unclear', $row->clarity);
            }

            return $row;
        });
    }

    /**
     * Put staged documents on the application (private disk). Existing documents are kept.
     *
     * @param  Collection<int, StagedAttachment>  $staged
     * @return Collection<int, Media>
     */
    public function attach(Model $owner, Collection $staged, string $actorId): Collection
    {
        return $staged->map(function (StagedAttachment $row) use ($owner, $actorId) {
            $ext = $row->mime === 'application/pdf' ? 'pdf' : 'jpg';
            $media = $owner->addMedia($row->absolutePath())
                ->preservingOriginal()
                ->usingFileName(Str::uuid().'.'.$ext)
                ->usingName($row->original_name ? pathinfo($row->original_name, PATHINFO_FILENAME) : 'attachment')
                ->withCustomProperties([
                    'original_name' => $row->original_name, 'uploaded_by' => $actorId, 'kind' => $row->kind,
                    'pages' => $row->page_count, 'clarity' => $row->clarity['metrics'] ?? null,
                ])
                ->toMediaCollection('attachments', config('attachments.disk'));
            foreach ((array) $row->preview_paths as $i => $previewPath) {
                $owner->addMedia(StagedAttachment::disk()->path($previewPath))->preservingOriginal()->usingFileName(Str::uuid().'.jpg')
                    ->withCustomProperties(['for' => $media->id, 'page' => $i + 1])->toMediaCollection('attachment_previews', config('attachments.disk'));
            }
            $row->update(['attached_at' => now()]);

            return $media;
        });
    }

    /** The employee's re-upload: the old documents stay in history (marked superseded) and the new ones become current. */
    public function replace(Model $owner, Collection $staged, string $actorId): Collection
    {
        $owner->getMedia('attachments')->each(function (Media $m) {
            if (! $m->getCustomProperty('superseded_at')) {
                $m->setCustomProperty('superseded_at', now()->toIso8601String());
                $m->save();
            }
        });
        $media = $this->attach($owner, $staged, $actorId);

        AttachmentEvent::create([
            'subject_type' => $owner::attachmentSubjectType(), 'subject_id' => $owner->getKey(), 'actor_id' => $actorId,
            'action' => 'reuploaded', 'meta' => ['media_ids' => $media->pluck('id')->all()],
        ]);

        return $media;
    }

    /** Remove expired, unattached staged files (attached ones are already copied into the application). */
    public function pruneExpired(): int
    {
        $count = 0;
        StagedAttachment::query()->where(fn ($q) => $q->where('expires_at', '<', now())->orWhereNotNull('attached_at'))
            ->where('updated_at', '<', now()->subHour())->chunkById(100, function ($rows) use (&$count) {
                foreach ($rows as $row) {
                    StagedAttachment::disk()->delete(array_filter([$row->path, $row->original_path, ...(array) $row->preview_paths]));
                    $row->delete();
                    $count++;
                }
            });

        return $count;
    }

    // ---------------------------------------------------------------- file handling

    private function store(string $userId, string $kind, string $bytes, string $mime, ?string $name, ClarityResult $result, int $pages = 1): StagedAttachment
    {
        $path = config('attachments.staging_dir').'/'.Str::uuid().($mime === 'application/pdf' ? '.pdf' : '.jpg');
        StagedAttachment::disk()->put($path, $bytes);

        return StagedAttachment::create([
            'token' => (string) Str::uuid(), 'user_id' => $userId, 'kind' => $kind, 'path' => $path, 'mime' => $mime,
            'original_name' => $name !== null ? Str::limit(basename($name), 200, '') : null, 'size' => strlen($bytes), 'page_count' => $pages,
            'clarity' => $result->toArray(), 'clarity_passed' => $result->passed,
            'expires_at' => now()->addHours((int) config('attachments.staging_ttl_hours')),
        ]);
    }

    private function readUpload(UploadedFile $file): string
    {
        if (! $file->isValid()) {
            throw new AttachmentRejected(__('attachments.clarity.unreadable'), 'unreadable');
        }
        if ($file->getSize() > (int) config('attachments.limits.max_bytes')) {
            throw new AttachmentRejected(__('attachments.errors.too_large'), 'too_large');
        }

        return (string) file_get_contents($file->getRealPath());
    }

    /** The real type, from the content (the extension and the client's claim are ignored). */
    private function sniffMime(string $path): string
    {
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $accepted = (array) config('attachments.accepted_mimes');
        if (! in_array($mime, $accepted, true)) {
            throw new AttachmentRejected(__('attachments.errors.unsupported_type'), 'unsupported_type');
        }

        return $mime;
    }

    /** Decode, orient upright and flatten transparency; HEIC goes through Imagick when it can read it. */
    private function decodeImage(string $path, string $bytes, string $mime): GdImage
    {
        if (in_array($mime, ['image/heic', 'image/heif'], true)) {
            $bytes = $this->heicToJpeg($path);
        }

        $info = @getimagesizefromstring($bytes);
        if (! $info) {
            throw new AttachmentRejected(__('attachments.clarity.unreadable'), 'unreadable');
        }
        if ((int) config('attachments.limits.max_pixels') < $info[0] * $info[1]) {
            throw new AttachmentRejected(__('attachments.errors.too_many_pixels'), 'too_many_pixels');
        }

        $cap = (int) config('attachments.scanner.decode_max_long_edge', 3000);
        $scale = min(1.0, $cap / max($info[0], $info[1]));
        $fitW = max(1, (int) round($info[0] * $scale));
        $fitH = max(1, (int) round($info[1] * $scale));

        $image = $scale < 1.0 ? $this->decodeShrunk($bytes, $fitW, $fitH) : null;
        if (! $image) {
            $this->reserveMemoryFor($info[0] * $info[1]);
            $image = @imagecreatefromstring($bytes);
            if ($image && $scale < 1.0) {
                $image = imagescale($image, $fitW, $fitH, IMG_BILINEAR_FIXED) ?: $image;
            }
        }
        if (! $image) {
            throw new AttachmentRejected(__('attachments.clarity.unreadable'), 'unreadable');
        }

        // EXIF orientation is applied to the pixels (the metadata itself is dropped on re-encoding).
        if (($info['mime'] ?? '') === 'image/jpeg' && function_exists('exif_read_data')) {
            $tmp = tempnam(sys_get_temp_dir(), 'exif_');
            file_put_contents($tmp, $bytes);
            $exif = @exif_read_data($tmp);
            @unlink($tmp);
            $rotated = match ((int) ($exif['Orientation'] ?? 1)) {
                3 => imagerotate($image, 180, 0),
                6 => imagerotate($image, -90, 0),
                8 => imagerotate($image, 90, 0),
                default => $image,
            };
            $image = $rotated ?: $image;
        }

        // Flatten any transparency onto white.
        $flat = imagecreatetruecolor(imagesx($image), imagesy($image));
        imagefill($flat, 0, 0, 0xFFFFFF);
        imagecopy($flat, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));

        return $flat;
    }

    /**
     * Decode a large photo straight to $w x $h with Imagick: the jpeg:size hint lets libjpeg shrink while decoding,
     * so the full-size bitmap never exists in PHP memory. Metadata is stripped; the EXIF orientation is applied
     * afterwards from the original bytes. Null when Imagick is missing or cannot read the image.
     */
    private function decodeShrunk(string $bytes, int $w, int $h): ?GdImage
    {
        if (! class_exists(\Imagick::class)) {
            return null;
        }
        try {
            $im = new \Imagick;
            $im->setOption('jpeg:size', $w.'x'.$h);
            $im->readImageBlob($bytes);
            $im->thumbnailImage($w, $h, true);
            $im->setImageBackgroundColor('white');
            $im->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
            $im->setImageFormat('jpeg');
            $im->setImageCompressionQuality(95);
            $blob = $im->getImageBlob();
            $im->clear();

            return @imagecreatefromstring($blob) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * GD holds a decoded photo at about 5 bytes a pixel. Make room for it, or refuse the photo with a clear message
     * rather than letting the request die on the memory limit.
     */
    private function reserveMemoryFor(int $pixels): void
    {
        $limit = ini_parse_quantity((string) ini_get('memory_limit'));
        if ($limit <= 0) {
            return;
        }
        $need = memory_get_usage(true) + $pixels * 5 * 2 + 32 * 1024 * 1024;
        if ($need > $limit && ini_set('memory_limit', (string) $need) === false) {
            throw new AttachmentRejected(__('attachments.errors.too_many_pixels'), 'too_many_pixels');
        }
    }

    private function heicToJpeg(string $path): string
    {
        if (! class_exists(\Imagick::class)) {
            throw new AttachmentRejected(__('attachments.errors.heic_unsupported'), 'heic_unsupported');
        }
        try {
            $im = new \Imagick($path);
            $im->setImageFormat('jpeg');
            $im->setImageCompressionQuality(92);
            $im->autoOrient();
            $bytes = $im->getImageBlob();
            $im->clear();

            return $bytes;
        } catch (\Throwable) {
            throw new AttachmentRejected(__('attachments.errors.heic_unsupported'), 'heic_unsupported');
        }
    }

    private function encodeJpeg(GdImage $image, int $quality): string
    {
        ob_start();
        imagejpeg($image, null, $quality);

        return (string) ob_get_clean();
    }

    private function validMode(string $mode): string
    {
        return in_array($mode, (array) config('attachments.scanner.modes'), true) ? $mode : 'color';
    }
}
