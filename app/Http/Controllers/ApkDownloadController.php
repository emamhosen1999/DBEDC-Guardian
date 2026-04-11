<?php

// This controller will serve the latest APK file for download

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ApkDownloadController extends Controller
{
    public function latest(Request $request): StreamedResponse
    {
        // Path to the latest APK in storage/app/public/apk/latest.apk
        $apkPath = storage_path('app/public/apk/latest.apk');
        if (! file_exists($apkPath)) {
            abort(404, 'APK not found');
        }
        $filename = 'dbedc-mobile-app-latest.apk';

        return response()->streamDownload(function () use ($apkPath) {
            readfile($apkPath);
        }, $filename, [
            'Content-Type' => 'application/vnd.android.package-archive',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }
}
