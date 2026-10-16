<?php

namespace App\Console\Commands;

use App\Services\Attachments\AttachmentService;
use Illuminate\Console\Command;

/** Scans and uploads that were never attached to an application expire after 24 h. */
class PruneStagedAttachments extends Command
{
    protected $signature = 'attachments:prune';

    protected $description = 'Delete expired, unattached staged uploads and scans';

    public function handle(AttachmentService $attachments): int
    {
        $this->info($attachments->pruneExpired().' staged file(s) removed.');

        return self::SUCCESS;
    }
}
