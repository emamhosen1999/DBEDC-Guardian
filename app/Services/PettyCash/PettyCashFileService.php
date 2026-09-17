<?php

namespace App\Services\PettyCash;

use App\Models\PettyCashTransaction;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class PettyCashFileService
{
    public function uploadBill(PettyCashTransaction $transaction, UploadedFile $file): array
    {
        if ($transaction->getMedia('bills')->count() >= PettyCashTransaction::MAX_BILLS) {
            throw ValidationException::withMessages([
                'bill' => 'This transaction already has the maximum of '.PettyCashTransaction::MAX_BILLS.' bills. Remove one before adding another.',
            ]);
        }

        $media = $transaction->addMedia($file)
            ->usingFileName($this->generateUniqueFileName($file))
            ->toMediaCollection('bills');
        $transaction->touch();

        return [
            'id' => $media->id,
            'name' => $media->file_name,
            'original_name' => $media->name,
            'url' => $media->getUrl(),
            'mime_type' => $media->mime_type,
            'size' => $media->size,
            'human_size' => $this->formatBytes($media->size),
            'is_image' => str_starts_with($media->mime_type, 'image/'),
            'is_pdf' => $media->mime_type === 'application/pdf',
        ];
    }

    public function getBills(PettyCashTransaction $transaction): array
    {
        return $transaction->getMedia('bills')->map(function ($media) {
            return [
                'id' => $media->id,
                'name' => $media->file_name,
                'original_name' => $media->name,
                'url' => $media->getUrl(),
                'mime_type' => $media->mime_type,
                'size' => $media->size,
                'human_size' => $this->formatBytes($media->size),
                'is_image' => str_starts_with($media->mime_type, 'image/'),
                'is_pdf' => $media->mime_type === 'application/pdf',
                'created_at' => $media->created_at->toISOString(),
            ];
        })->toArray();
    }

    public function deleteBill(PettyCashTransaction $transaction, int $mediaId): bool
    {
        $media = $transaction->getMedia('bills')->where('id', $mediaId)->first();

        if (! $media) {
            return false;
        }

        $media->delete();
        $transaction->touch();

        return true;
    }

    private function generateUniqueFileName(UploadedFile $file): string
    {
        $extension = $file->getClientOriginalExtension();
        $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $sanitizedName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $originalName);

        return $sanitizedName.'_'.uniqid().'.'.$extension;
    }

    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);

        return round($bytes, $precision).' '.$units[$pow];
    }
}
