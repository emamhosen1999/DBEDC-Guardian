<?php

return [
    'clarity' => [
        'ok' => 'The document is clear.',
        'blurry' => 'This scan is too blurry to read. Hold the phone steady, use good light and fill the frame with the document, then upload it again.',
        'dark' => 'This photo is too dark to read. Move to a brighter place or turn on a light, then take it again.',
        'bright' => 'This photo is too bright and washed out. Avoid direct glare or flash on the paper, then take it again.',
        'low_contrast' => 'The text does not stand out from the paper. Use even light, hold the phone parallel to the page and take it again.',
        'too_small' => 'This image is too small to read. Move closer so the document fills the frame, or use a higher-resolution photo, then upload it again.',
        'blank' => 'The page looks blank. Make sure the document is facing the camera and fully in the frame, then upload it again.',
        'unreadable' => 'This file could not be read as an image or PDF. Please upload a clear photo, JPEG, PNG or PDF.',
    ],
    'errors' => [
        'unsupported_type' => 'Only PDF, JPEG, PNG and HEIC files can be attached.',
        'heic_unsupported' => 'This phone photo format (HEIC) cannot be read here. Please take the photo as JPEG or choose another file.',
        'too_large' => 'Each file must be 10 MB or smaller.',
        'too_many_files' => 'You can attach at most :max files.',
        'too_many_pages' => 'A PDF can have at most :max pages.',
        'too_many_pixels' => 'This image is too large to process. Please use a smaller photo.',
        'staged_missing' => 'An attached document has expired or does not belong to you. Please upload it again.',
        'staged_unclear' => 'An attached document did not pass the clarity check. Please upload a clear one.',
        'required' => 'A clear supporting document is required for this application.',
        'required_leave_always' => '":type" requires a clear supporting document.',
        'required_leave_days' => '":type" over :days day(s) requires a clear supporting document.',
    ],
    'reupload' => [
        'notification_title' => 'Please upload a clearer document',
        'notification_body' => 'Your :what needs a new attachment: :reason',
        'waiting' => 'This application was returned to the employee for a clearer document. It can be decided once they upload a new one.',
        'reason_required' => 'Say what is wrong with the document so the employee can fix it.',
        'not_pending' => 'Only a pending application can be returned for re-upload.',
        'not_returned' => 'This application has not been returned for re-upload.',
    ],
];
