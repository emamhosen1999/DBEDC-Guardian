<?php

/*
|--------------------------------------------------------------------------
| Application attachments (leave, overtime claims, replacement leave)
|--------------------------------------------------------------------------
| One server-side pipeline for the web and the mobile API: accept, re-encode, clarity-check, store privately.
| HR can tune every threshold here (or through the matching ATTACH_* environment variables).
| The clarity values were calibrated on synthetic documents (tests/Support/Attachments/SyntheticDocuments.php): every
| sharp page passes and every blurred, dark, washed-out, low-resolution or blank page fails.
*/
return [
    // Private (never public) disk for uploaded documents; they may contain medical data.
    'disk' => env('ATTACH_DISK', 'attachments'),
    'staging_dir' => 'staging',

    'limits' => [
        'max_bytes' => (int) env('ATTACH_MAX_BYTES', 10 * 1024 * 1024),
        'max_files' => (int) env('ATTACH_MAX_FILES', 3),
        'max_pdf_pages' => (int) env('ATTACH_MAX_PDF_PAGES', 10),
        'max_scan_pages' => 10,
        // Pixel budget guard against decompression bombs (width x height).
        'max_pixels' => 80_000_000,
    ],

    // Detected from the file CONTENT, never the extension.
    'accepted_mimes' => ['application/pdf', 'image/jpeg', 'image/png', 'image/heic', 'image/heif'],

    // A staged (uploaded or scanned) file waits this long for the application to be submitted.
    'staging_ttl_hours' => 24,

    'clarity' => [
        // About 150 dpi on an A4 page.
        'min_long_edge' => (int) env('ATTACH_MIN_LONG_EDGE', 1200),
        'min_short_edge' => (int) env('ATTACH_MIN_SHORT_EDGE', 800),

        // Sharpness: Laplacian variance of the grey image scaled to this long edge, taken over the busiest
        // (text-bearing) tiles so that a sparse page is not mistaken for a blurred one.
        'sharpness_long_edge' => 1000,
        'sharpness_tile' => 32,
        'sharpness_top_tile_fraction' => 0.15,
        'min_sharpness' => (float) env('ATTACH_MIN_SHARPNESS', 110.0),

        // Exposure on the grey image.
        'min_mean_luminance' => (float) env('ATTACH_MIN_MEAN', 40.0),
        'max_mean_luminance' => (float) env('ATTACH_MAX_MEAN', 225.0),
        // Ink contrast = paper level (median) minus ink level (1st percentile). A washed-out or flat image fails.
        // A bright, high-key page (mean above max_mean_luminance) is still fine when its ink contrast is strong.
        'min_ink_contrast' => (float) env('ATTACH_MIN_INK_CONTRAST', 70.0),

        // Not blank: share of pixels that are ink (clearly darker than the paper) or on an edge.
        'min_ink_fraction' => (float) env('ATTACH_MIN_INK_FRACTION', 0.002),
    ],

    // Text PDFs only need the page and size limits; scanned PDFs are rasterised at this resolution and each page is checked.
    'pdf' => [
        'raster_dpi' => 150,
        'check_pages' => 3,
    ],

    'scanner' => [
        'detect_long_edge' => 480,
        // Below this the document edges are guessed, so the review screen opens "Adjust corners" automatically.
        'min_confidence' => 0.6,
        'output_max_long_edge' => 1800,
        // Phone cameras shoot 12-200 MP; photos are decoded no larger than this (long edge) so one upload
        // never needs hundreds of MB of PHP memory. Well above the output size and the clarity minimum.
        'decode_max_long_edge' => 3000,
        'jpeg_quality' => 88,
        'modes' => ['color', 'grey', 'bw'],
    ],
];
