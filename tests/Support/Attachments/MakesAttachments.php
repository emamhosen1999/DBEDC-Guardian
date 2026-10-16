<?php

namespace Tests\Support\Attachments;

use App\Models\User;
use App\Services\Attachments\AttachmentService;
use App\Services\Attachments\Contracts\PdfRasterizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/** Helpers: private attachments disk faked, synthetic documents (never real employee files) staged like a real upload. */
trait MakesAttachments
{
    private static ?string $sharpJpeg = null;

    protected function fakeAttachments(?FakePdfRasterizer $pdf = null): FakePdfRasterizer
    {
        $this->raiseMemoryForImages();
        Storage::fake('attachments');
        $pdf ??= new FakePdfRasterizer;
        $this->app->instance(PdfRasterizer::class, $pdf);

        return $pdf;
    }

    /** Synthetic photos are millions of pixels (GD keeps them uncompressed): the default 128 MB limit is not enough on its own. */
    protected function raiseMemoryForImages(): void
    {
        if ((int) ini_get('memory_limit') !== -1 && (int) ini_get('memory_limit') < 1536) {
            ini_set('memory_limit', '1536M');
        }
    }

    protected function sharpJpegBytes(): string
    {
        return self::$sharpJpeg ??= SyntheticDocuments::jpeg(SyntheticDocuments::page(), 88);
    }

    protected function upload(string $bytes, string $name = 'scan.jpg'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $bytes);
    }

    /** Stage a clear document for the user and return its token. */
    protected function clearToken(User $user): string
    {
        return app(AttachmentService::class)->stageUpload($this->upload($this->sharpJpegBytes()), (string) $user->employee_id)->token;
    }
}
