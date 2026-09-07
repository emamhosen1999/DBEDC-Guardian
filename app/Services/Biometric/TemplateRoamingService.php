<?php

namespace App\Services\Biometric;

use App\Models\HRM\BiometricDevice;
use App\Models\HRM\BiometricDeviceCommand;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Biometric roaming — the write-back half.
 *
 * `BiometricProcessingService::processTemplateUpload()` captures fingerprint
 * (`table=templatev10`) and face (`table=facetmpv10`) templates into
 * `biometric_templates`. Nothing has ever been able to push them back, so a dead
 * or replaced unit means every enrolled person walks to the device and re-enrols
 * by hand even though we are holding their template.
 *
 * This service closes that loop by queueing `DATA UPDATE FINGERTMP` commands
 * (docs/zkteco-adms-capability-matrix.md §2). That command is marked `[D]` in the
 * matrix — documented consistently across independent implementations, but not
 * yet demonstrated on our own hardware. Until an MB460 acks one with `Return=0`,
 * a restore is a *plausible* write, not a proven one. The UI wording and anyone
 * reading command history should treat -1002/-1004 on these commands as
 * "our string or this model", not as a device fault.
 *
 * ONE COMMAND PER FINGER, not per person. Capture used to discard `FID` and key
 * its write on (PIN, device, modality), so a person's second enrolled finger
 * overwrote their first and a "complete" restore returned about half of the
 * production MB460's 26 fingerprints across 13 employees. Capture now stores the
 * finger index and `biometric_templates` carries a unique key over the slot, so
 * the loop below emits a distinct `DATA UPDATE FINGERTMP` — with the real `FID` —
 * for every finger a person enrolled.
 *
 * Face templates are deliberately NOT restorable here — see FACE_REASON.
 */
class TemplateRoamingService
{
    /**
     * Maximum commands a single restore call may queue.
     *
     * This is not a database concern, it is a queue-drain concern.
     * `/iclock/getrequest` hands the device exactly ONE command per poll
     * (BiometricWebhookController::getRequest -> fetchNextPendingCommand), the
     * queue is strictly FIFO on created_at, and ZKTeco units poll every 30-120 s.
     * So N queued templates is N polls: 200 commands is roughly 1.5-7 hours during
     * which *nothing else* — no REBOOT, no SET_TIME, no CHECK_ATTLOG — can reach
     * that device.
     *
     * 200 is chosen to sit comfortably above the real fleet (the production MB460
     * holds 26 fingerprints across 13 employees, and a 10-finger enrolment for 20
     * people is still only 200) while making "restore all 3000 users" impossible
     * to trigger by accident. A larger job is a legitimate need, but it belongs in
     * a paced background job, not in one click that silently wedges a device for
     * a day.
     */
    public const MAX_COMMANDS_PER_RESTORE = 200;

    /**
     * Refuse to push a template this large (bytes, after whitespace is stripped).
     *
     * ZKTeco does not publish a command-length limit and our own transport imposes
     * none — the ADMS string is the entire HTTP response body of a getrequest, so
     * PHP and the device's own command buffer are the only ceilings. Every
     * reference implementation sends a fingerprint template as ONE
     * `DATA UPDATE FINGERTMP`; none chunk it, and no chunking/continuation syntax
     * is documented anywhere. So: no chunking here either.
     *
     * A base64 ZK v10 fingerprint template runs roughly 600 B - 2.5 KB. 8 KB is
     * generous headroom for that, while still refusing a blob that is almost
     * certainly a face/BIODATA payload misfiled as a fingerprint — which the
     * device would silently truncate into a corrupt enrolment.
     *
     * ASSUMPTION, unverified on hardware: one command carries a whole template.
     */
    public const MAX_TEMPLATE_BYTES = 8192;

    /**
     * FID stored, and sent, when a fingerprint push carried no finger index.
     *
     * This used to describe a schema gap: capture never looked for `FID` at all
     * and keyed its write on (device_user_id, device, template_type), so a second
     * finger overwrote the first and every restore landed on slot 0. That is
     * fixed — `processTemplateUpload()` now parses `FID` and
     * `biometric_templates` carries a unique key over the finger slot, so two
     * fingers are two rows and produce two commands.
     *
     * What remains is the honest residue: a device that omits `FID` (and every
     * row captured before the fix, backfilled by
     * 2026_08_05_000001_add_finger_slot_unique_to_biometric_templates_table)
     * genuinely has no known finger, and slot 0 is where such a template has
     * always been restored. Keeping the same value means those rows behave
     * exactly as they did before, and are replaced in place the next time the
     * device pushes that person's real finger 0.
     */
    public const FALLBACK_FINGER_INDEX = 0;

    /**
     * Stored finger index for a modality that has no finger: face, palm.
     *
     * Not 0, and not NULL, for two different reasons.
     *
     * Not 0, because 0 is a real finger. A face row keyed at slot 0 shares its
     * slot identity with somebody's thumb, and a device addresses a template by
     * PIN + FID with no modality in the address at all — so the one guarantee
     * worth having is that a face can never occupy a finger's slot.
     *
     * Not NULL, because MySQL and SQLite both treat NULLs in a unique index as
     * always distinct. A nullable finger index would be invisible to the very
     * constraint that stops one finger overwriting another, and the defect would
     * have survived its own fix.
     *
     * Negative by design: it cannot be forged by a device, since a reported FID
     * below 0 is rejected at capture.
     */
    public const NO_FINGER_INDEX = -1;

    /**
     * Highest finger index the protocol defines. Ten fingers, addressed 0-9.
     *
     * Used to reject a caller-supplied FID, not a device-supplied one: capture
     * stores an out-of-range index verbatim rather than clamping it (see
     * BiometricProcessingService::matchTemplateFingerIndex()), because clamping
     * would overwrite a real finger to hide a firmware surprise.
     */
    public const MAX_FINGER_INDEX = 9;

    /**
     * Why face templates are captured but never restored.
     *
     * This was re-investigated rather than inherited, because "we could not find
     * it" and "it does not exist" are different claims. Four findings, in
     * increasing order of how decisive they are:
     *
     * 1. No `DATA UPDATE FACE` verb exists in any source. The reference
     *    implementations the matrix cites implement INFO, CHECK, GET OPTION,
     *    DATA UPDATE/DELETE/QUERY USERINFO and DATA UPDATE/QUERY FINGERTMP — and
     *    stop there. The Go library (s0x90) states outright that it ships only
     *    commands confirmed on real hardware, and face is not among them.
     *
     * 2. The one face-write form that does exist anywhere is
     *    `DATA UPDATE BIODATA Pin= No= Index= Valid= Duress= Type=9 MajorVer=
     *    MinorVer= Format= Tmp=`, from a single ZKTeco distributor's command
     *    guide. Single-source, and `Type=9` is *visible* face — the visible-light
     *    engine on the SpeedFace class of device.
     *
     * 3. Our MB460 does not speak that dialect. It pushes `table=facetmpv10`, the
     *    older per-modality face table; BIODATA is the newer unified firmware path
     *    (matrix §1, `[D]`) and this server does not even accept those pushes, so
     *    we have never seen what an MB460 would emit in that format. Writing a
     *    facetmpv10 blob into a BIODATA command is a format assumption on top of a
     *    single-source syntax.
     *
     * 4. DECISIVE, and checkable without any hardware: we could not build the
     *    command even if the syntax were certain. `BiometricProcessingService::
     *    processTemplateUpload()` captures exactly two things from a template
     *    push — `USERID` and `TMP`. `MajorVer`, `MinorVer`, `Format`, `Index`,
     *    `No` and `Duress` are discarded at capture and have no columns in
     *    `biometric_templates`. Those are the fields that tell the device which
     *    face algorithm the blob belongs to. Guessing them is guessing which
     *    engine should decode somebody's face.
     *
     * A wrong template-write fails silently: the device acks, nothing is restored,
     * and it is discovered during a real recovery. So face rows are listed (the
     * gap stays visible) and skipped with this reason.
     *
     * To close this properly, in order: capture the discarded BIODATA fields, get
     * one MB460 to push `table=BIODATA`, then probe a single write against a test
     * unit. Not before.
     */
    public const FACE_REASON = 'face_write_back_not_established';

    /**
     * The same fact, phrased for a human.
     *
     * `not_restorable_reason` is rendered verbatim into the tooltip behind the
     * "Listed only" badge (BiometricPanel.jsx), so a bare snake_case key would put
     * `face_write_back_not_established` in front of an administrator. An honest
     * unavailability is only useful if it is also actionable: say what is missing
     * and what would change it.
     */
    public const FACE_REASON_MESSAGE = 'Face template write-back is not established for this protocol version. This device pushes face templates as facetmpv10, and no server-to-device face write command is confirmed for it — the one candidate form needs template version fields that are discarded at capture. The template is stored and safe here, but it cannot be pushed back; the person must re-enrol their face on the device.';

    /**
     * Palm, and anything else that is neither fingerprint nor face.
     *
     * Same shape of gap, different modality, and worth its own wording so nobody
     * reads a palm row and assumes the face investigation covered it.
     */
    public const OTHER_MODALITY_REASON = 'template_type_not_restorable';

    public const OTHER_MODALITY_REASON_MESSAGE = 'Only fingerprint templates can be written back. `DATA UPDATE FINGERTMP` is the sole confirmed write-back command; there is no equivalent for this template type. It is stored and listed here, but a restore will skip it.';

    /**
     * Read model for the roaming UI.
     *
     * `template_data` is never selected. It is a multi-kilobyte base64 blob of
     * somebody's biometric; it has no business crossing an HTTP boundary into a
     * browser, and shipping it would turn an admin screen into a template
     * exfiltration endpoint. Sizes and identifiers are enough to decide what to
     * restore.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function listTemplates(?string $userId = null, ?int $deviceId = null): Collection
    {
        $query = DB::table('biometric_templates as t')
            ->leftJoin('users as u', 'u.id', '=', 't.user_id')
            ->leftJoin('biometric_devices as d', 'd.id', '=', 't.biometric_device_id')
            ->select([
                't.id',
                't.user_id',
                'u.name as user_name',
                'u.employee_id',
                't.device_user_id',
                't.template_type',
                't.finger_index',
                't.template_size',
                't.template_version',
                't.biometric_device_id',
                'd.name as device_name',
                'd.serial_number as device_serial',
                't.created_at',
                't.updated_at',
            ]);

        if ($userId !== null) {
            $query->where('t.user_id', $userId);
        }

        if ($deviceId !== null) {
            $query->where('t.biometric_device_id', $deviceId);
        }

        return $query
            ->orderBy('t.user_id')
            ->orderBy('t.template_type')
            ->orderBy('t.finger_index')
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'user_id' => $row->user_id === null ? null : (string) $row->user_id,
                'user_name' => $row->user_name,
                'employee_id' => $row->employee_id,
                // The device-side PIN. This is what a restore command carries.
                'pin' => (string) $row->device_user_id,
                'template_type' => $row->template_type,
                // The sentinel is a storage detail, not something to render: a
                // face row shows "no finger", never "finger -1". Null is what the
                // UI already treats as "unknown / not applicable", so the read
                // model keeps that contract and the panel needs no change.
                'finger_index' => $this->displayFingerIndex($row->finger_index),
                'template_size' => $row->template_size === null ? null : (int) $row->template_size,
                'template_version' => $row->template_version,
                'source_device_id' => $row->biometric_device_id === null ? null : (int) $row->biometric_device_id,
                'source_device_name' => $row->device_name,
                'source_device_serial' => $row->device_serial,
                'captured_at' => $row->created_at,
                'updated_at' => $row->updated_at,
                // Surfaced so the UI can grey out face rows with a reason rather
                // than offering a restore that cannot work. Two fields on purpose:
                // `_reason` is the sentence the tooltip shows an admin, `_code` is
                // the stable key to branch on. Collapsing them would force either
                // a machine key into the UI or free text into a comparison.
                'restorable' => $row->template_type === 'fingerprint',
                'not_restorable_reason' => $this->notRestorableMessage((string) $row->template_type),
                'not_restorable_code' => $this->notRestorableCode((string) $row->template_type),
            ])
            ->values();
    }

    /**
     * Queue one `DATA UPDATE FINGERTMP` per stored fingerprint template — which,
     * since capture became finger-aware, means one per FINGER rather than one per
     * person.
     *
     * An empty `$userIds` means every user we hold a template for. Templates the
     * target device already has are skipped, as are face/palm templates and
     * anything that cannot be sent safely; every skip is counted under a reason so
     * the UI can explain a restore that queued fewer commands than expected.
     *
     * @param  array<int, int|string>  $userIds
     * @return array{queued: int, skipped: int, users: int, reasons: array<string, int>}
     *
     * @throws \InvalidArgumentException when the target cannot receive commands
     */
    public function restoreTemplatesToDevice(BiometricDevice $target, array $userIds = []): array
    {
        // Same guards, same order, same exception type as
        // BiometricProcessingService::initiateLogDownload(). A restore is a write
        // onto hardware, so it is refused loudly rather than queued into a device
        // that will never poll for it.
        if (! $target->is_active) {
            throw new \InvalidArgumentException('Device is inactive.');
        }

        if (! $target->isAdms()) {
            throw new \InvalidArgumentException('Template restore is only supported for ADMS devices.');
        }

        $reasons = [];
        $skipped = 0;
        $skip = function (string $reason) use (&$reasons, &$skipped) {
            $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
            $skipped++;
        };

        $userIds = array_values(array_filter(array_map('intval', $userIds)));

        $candidates = DB::table('biometric_templates')
            ->select([
                'id', 'user_id', 'biometric_device_id', 'device_user_id',
                'template_type', 'finger_index', 'template_data', 'template_size',
            ])
            ->when($userIds !== [], fn ($q) => $q->whereIn('user_id', $userIds))
            // Newest first so that when two source devices hold the same finger
            // for the same person, the freshest enrolment wins the de-duplication
            // below.
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get();

        // Everything the target already holds, keyed the same way we key the
        // commands we are about to queue. A template captured *from* this device
        // is in here too, which is exactly right: restoring it would be a no-op.
        $onTarget = DB::table('biometric_templates')
            ->where('biometric_device_id', $target->id)
            ->get(['device_user_id', 'template_type', 'finger_index'])
            ->map(fn ($row) => $this->slotKey(
                (string) $row->device_user_id,
                (string) $row->template_type,
                $row->finger_index
            ))
            // A person with two fingers on the target yields two DISTINCT keys,
            // so restoring their third finger is no longer mistaken for a no-op.
            ->flip();

        /** @var array<string, object> $selected */
        $selected = [];

        foreach ($candidates as $row) {
            if ($row->template_type !== 'fingerprint') {
                // Face and palm: captured, listed, never pushed. See FACE_REASON.
                $skip($this->notRestorableCode((string) $row->template_type));

                continue;
            }

            $template = preg_replace('/\s+/', '', (string) $row->template_data);

            if ($template === '') {
                $skip('empty_template');

                continue;
            }

            if (strlen($template) > self::MAX_TEMPLATE_BYTES) {
                $skip('template_too_large');

                continue;
            }

            $key = $this->slotKey((string) $row->device_user_id, 'fingerprint', $row->finger_index);

            if ($onTarget->has($key)) {
                $skip('already_on_device');

                continue;
            }

            if (isset($selected[$key])) {
                // Same person, same finger slot, held by a second source device.
                // Pushing both would queue two commands that write the same slot.
                $skip('duplicate_template');

                continue;
            }

            $row->normalised_template = $template;
            $selected[$key] = $row;
        }

        // Deterministic queue order: by person, then finger slot, so a person's
        // fingers arrive at the device in slot order. The dedupe pass above ran
        // newest-first, which is the wrong order to hand to a device.
        $queueable = array_values($selected);
        usort($queueable, function ($a, $b) {
            return [$a->user_id, $this->fingerIndexFor($a), $a->id]
                <=> [$b->user_id, $this->fingerIndexFor($b), $b->id];
        });

        if (count($queueable) > self::MAX_COMMANDS_PER_RESTORE) {
            foreach (array_slice($queueable, self::MAX_COMMANDS_PER_RESTORE) as $ignored) {
                $skip('cap_reached');
            }
            $queueable = array_slice($queueable, 0, self::MAX_COMMANDS_PER_RESTORE);
        }

        $queued = [];

        DB::transaction(function () use ($queueable, $target, &$queued) {
            foreach ($queueable as $row) {
                // The REAL finger index the device reported at capture. The
                // fallback only ever applies to a template that arrived without
                // one — it is no longer what every restore silently sends.
                $fid = $this->fingerIndexFor($row);
                $size = strlen($row->normalised_template);

                $command = BiometricDeviceCommand::create([
                    'biometric_device_id' => $target->id,
                    'command_type' => 'UPDATE_FINGERTMP',
                    'payload' => [
                        'pin' => (string) $row->device_user_id,
                        'fid' => (int) $fid,
                        'size' => $size,
                        'valid' => 1,
                        'template' => $row->normalised_template,
                        // Provenance, for the audit trail on the command row itself.
                        'template_id' => (int) $row->id,
                        'source_device_id' => (int) $row->biometric_device_id,
                    ],
                    'status' => BiometricDeviceCommand::STATUS_PENDING,
                ]);

                $queued[] = [
                    'command_id' => $command->id,
                    'template_id' => (int) $row->id,
                    'user_id' => (string) $row->user_id,
                    'pin' => (string) $row->device_user_id,
                    'fid' => (int) $fid,
                    'size' => $size,
                    'source_device_id' => (int) $row->biometric_device_id,
                ];
            }
        });

        // Writing biometric data onto hardware is an auditable act. One bounded
        // summary line (capped at MAX_COMMANDS_PER_RESTORE entries) records who
        // asked, which device receives it, and exactly which template landed in
        // which finger slot.
        Log::info('Biometric template restore queued', [
            'device_id' => $target->id,
            'device_serial' => $target->serial_number,
            'requested_by' => auth()->id(),
            'requested_user_ids' => $userIds,
            'queued' => count($queued),
            'skipped' => $skipped,
            'reasons' => $reasons,
            'commands' => $queued,
            // The command itself is [D], not [V]: queued is not delivered, and
            // delivered is not accepted. Only a Return=0 ack proves the restore.
            'confidence' => 'DATA UPDATE FINGERTMP is documented but unverified on our hardware',
        ]);

        return [
            'queued' => count($queued),
            'skipped' => $skipped,
            'users' => count(array_unique(array_column($queued, 'user_id'))),
            'reasons' => $reasons,
        ];
    }

    /**
     * Queue a `DATA DELETE FINGERTMP` for one PIN.
     *
     * A null `$fid` omits the field, which the documented form reads as "all
     * fingers for this PIN". That verb is `[?]` in the matrix — single-source —
     * so a -1004 ack is an expected outcome on some models, not a bug.
     *
     * A non-null `$fid` must be a real finger, 0-9. Two values in particular are
     * refused rather than emitted: NO_FINGER_INDEX, which is our storage sentinel
     * for "this modality has no finger" and would go on the wire as `FID=-1`, and
     * anything else outside the protocol's range. This matters more for delete
     * than for write — a delete that mis-addresses its FID destroys the wrong
     * finger on the device, and our copy is not what is lost.
     *
     * UNVERIFIED AGAINST HARDWARE. No device of ours has acked a
     * `DATA DELETE FINGERTMP`; it is listed in
     * BiometricDeviceCommand::HARDWARE_UNVERIFIED_COMMAND_TYPES, and the
     * per-field justification for the string it emits is on that command's case
     * in `toAdmsString()`. It is also flagged destructive there. Queuing one is
     * not evidence it worked — only a Return=0 ack is.
     *
     * Nothing is removed from `biometric_templates`: our copy is the backup that
     * makes roaming possible, and the point of deleting on the device is usually
     * to move a person to another unit.
     *
     * @throws \InvalidArgumentException when the target cannot receive commands
     */
    public function deleteTemplateFromDevice(BiometricDevice $target, string $pin, ?int $fid = null): BiometricDeviceCommand
    {
        if (! $target->is_active) {
            throw new \InvalidArgumentException('Device is inactive.');
        }

        if (! $target->isAdms()) {
            throw new \InvalidArgumentException('Template delete is only supported for ADMS devices.');
        }

        $pin = trim($pin);

        if ($pin === '') {
            throw new \InvalidArgumentException('A device PIN is required.');
        }

        if ($fid !== null && ($fid < 0 || $fid > self::MAX_FINGER_INDEX)) {
            throw new \InvalidArgumentException('A finger index must be between 0 and '.self::MAX_FINGER_INDEX.'.');
        }

        $payload = ['pin' => $pin];

        if ($fid !== null) {
            $payload['fid'] = $fid;
        }

        $command = BiometricDeviceCommand::create([
            'biometric_device_id' => $target->id,
            'command_type' => 'DELETE_FINGERTMP',
            'payload' => $payload,
            'status' => BiometricDeviceCommand::STATUS_PENDING,
        ]);

        Log::info('Biometric template delete queued', [
            'device_id' => $target->id,
            'device_serial' => $target->serial_number,
            'requested_by' => auth()->id(),
            'command_id' => $command->id,
            'pin' => $pin,
            'fid' => $fid,
            'scope' => $fid === null ? 'all_fingers' : 'single_finger',
        ]);

        return $command;
    }

    /**
     * Stable machine key for why a template type cannot be written back, or null
     * when it can. This is what the skipped-reason breakdown aggregates on.
     */
    public function notRestorableCode(string $templateType): ?string
    {
        if ($templateType === 'fingerprint') {
            return null;
        }

        return $templateType === 'face' ? self::FACE_REASON : self::OTHER_MODALITY_REASON;
    }

    /**
     * The same answer as a sentence an administrator can act on. Rendered
     * verbatim in the "Listed only" tooltip.
     */
    public function notRestorableMessage(string $templateType): ?string
    {
        return match ($this->notRestorableCode($templateType)) {
            self::FACE_REASON => self::FACE_REASON_MESSAGE,
            self::OTHER_MODALITY_REASON => self::OTHER_MODALITY_REASON_MESSAGE,
            default => null,
        };
    }

    /**
     * Identity of a finger slot on a device: PIN + template type + effective FID.
     *
     * finger_index is normalised through effectiveFingerIndex(), so a stored NULL
     * and a stored 0 collide — they must, because both are pushed as `FID=0` and
     * would therefore write the same physical slot. Distinct real indices stay
     * distinct, which is what makes a two-finger restore two commands.
     */
    private function slotKey(string $pin, string $type, mixed $fingerIndex): string
    {
        return $pin.'|'.$type.'|'.$this->effectiveFingerIndex($fingerIndex);
    }

    /**
     * The FID a stored row is actually written to a device with.
     *
     * Anything null or negative — a row captured before FID was parsed, or the
     * face/palm sentinel — becomes slot 0, because that is the only slot such a
     * template can be addressed to. Everything else is the device's own number,
     * passed through unchanged; capture deliberately does not clamp an
     * out-of-range FID, and silently rewriting one here would let it land on a
     * real finger it does not belong to.
     */
    private function effectiveFingerIndex(mixed $fingerIndex): int
    {
        $fid = $fingerIndex === null ? self::FALLBACK_FINGER_INDEX : (int) $fingerIndex;

        return $fid < 0 ? self::FALLBACK_FINGER_INDEX : $fid;
    }

    /**
     * Same, for a template row selected out of `biometric_templates`.
     */
    private function fingerIndexFor(object $row): int
    {
        return $this->effectiveFingerIndex($row->finger_index ?? null);
    }

    /**
     * How a stored finger index is shown to a human.
     *
     * The sentinel means "this modality has no finger", which the UI already
     * renders from null. Surfacing -1 instead would put a storage detail in front
     * of an administrator.
     */
    private function displayFingerIndex(mixed $fingerIndex): ?int
    {
        if ($fingerIndex === null || (int) $fingerIndex === self::NO_FINGER_INDEX) {
            return null;
        }

        return (int) $fingerIndex;
    }
}
