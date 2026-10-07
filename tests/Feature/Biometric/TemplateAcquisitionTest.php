<?php

namespace Tests\Feature\Biometric;

use App\Models\HRM\BiometricDevice;
use App\Models\HRM\BiometricDeviceCommand;
use App\Models\User;
use App\Services\Biometric\BiometricProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Template ACQUISITION — the half of biometric roaming that never fired.
 *
 * The restore half was built, tested and shipped: `DATA UPDATE FINGERTMP`,
 * per-finger slots, a UI. It restores from `biometric_templates`, and that table
 * has always held zero rows. The production MB460 (`AF6P231260266`) holds 26
 * fingerprints and 1 face across 13 employees, has logged 13 "Enroll FP"
 * operations in OPERLOG, and has never once pushed a `table=templatev10`. The
 * backup exists in code and does not exist in fact.
 *
 * Capture was entirely passive — we waited to be given templates. This file
 * pins the fix, which is to ask for them, and pins the two things that must not
 * break while we do:
 *
 *  1. `QUERY_FINGERTMP` emits its exact documented string, in all three
 *     addressing forms, tab-separated between PIN and FID.
 *  2. The handshake still carries every key a live device needs. This is the
 *     regression guard that matters most in this file: `buildHandshakeOptionsBody()`
 *     is the live ingestion path for a real business, and `transFlag` was the
 *     tempting lever here. It is asserted UNCHANGED on purpose — see the block
 *     comment on that test.
 *  3. An incoming `templatev10` push is parsed and stored, including the
 *     multi-record two-fingers-one-user shape a query reply actually returns.
 *
 * A `DATA QUERY` returns its results as a PUSH, not in the ack, so 1 and 3 are
 * two halves of one path: emitting a perfect command that lands in a parser
 * which drops the reply would leave the table exactly as empty as it is today.
 */
class TemplateAcquisitionTest extends TestCase
{
    use RefreshDatabase;

    private function service(): BiometricProcessingService
    {
        return app(BiometricProcessingService::class);
    }

    private function device(array $overrides = []): BiometricDevice
    {
        return BiometricDevice::create(array_merge([
            'name' => 'Gate MB460',
            'serial_number' => 'SN-'.uniqid(),
            'protocol' => 'adms',
            'is_active' => true,
        ], $overrides));
    }

    private function command(string $type, ?array $payload = null): BiometricDeviceCommand
    {
        return BiometricDeviceCommand::create([
            'biometric_device_id' => $this->device()->id,
            'command_type' => $type,
            'payload' => $payload,
            'status' => 'pending',
        ]);
    }

    /**
     * The command string with the `C:<id>:` prefix stripped, so assertions read
     * as the wire payload rather than as a string containing a row id.
     */
    private function emitted(BiometricDeviceCommand $command): string
    {
        return substr($command->toAdmsString(), strlen("C:{$command->id}:"));
    }

    // ──────────────────────────────────────────────────────────────
    //  1. The new command — exact documented string
    // ──────────────────────────────────────────────────────────────

    /**
     * The whole-device dump: no PIN, no FID, nothing to get wrong.
     *
     * This is the form that answers the actual problem — an empty table on a
     * unit holding 26 fingerprints — and it is the direct analogue of the bare
     * `DATA QUERY USERINFO` that our MB460 acks Return=0 as a full roster dump.
     */
    public function test_query_fingertmp_with_no_payload_asks_for_every_template(): void
    {
        $command = $this->command('QUERY_FINGERTMP');

        $this->assertSame('DATA QUERY FINGERTMP', $this->emitted($command));
    }

    /**
     * PIN only — every finger belonging to one person.
     *
     * One space after the verb, exactly as `DATA QUERY USERINFO PIN=` and
     * `DATA UPDATE FINGERTMP PIN=` do.
     */
    public function test_query_fingertmp_scopes_to_a_pin(): void
    {
        $command = $this->command('QUERY_FINGERTMP', ['pin' => '1024']);

        $this->assertSame('DATA QUERY FINGERTMP PIN=1024', $this->emitted($command));
    }

    /**
     * PIN + FID — one finger slot — and the separator between them is a TAB.
     *
     * Asserted as the literal string rather than by `assertStringContainsString`,
     * because "contains PIN=1024" and "contains FID=3" would both pass on a
     * space-separated command that a device silently mis-parses. The distributor
     * guide that documents this command renders it `PIN=1 FID=1`, but that same
     * guide writes the sibling as `DATA UPDATE BIOPHOTO PIN=1\tContent=…` and
     * states that `\t` in its listings means a tab, so its spacing is not
     * evidence. What IS evidence is our own hardware: `DATA QUERY ATTLOG
     * StartTime=…\tEndTime=…` is the only multi-field DATA QUERY any device of
     * ours has acked Return=0, and it is tab-separated.
     */
    public function test_query_fingertmp_separates_pin_and_fid_with_a_tab(): void
    {
        $command = $this->command('QUERY_FINGERTMP', ['pin' => '1024', 'fid' => 3]);

        $this->assertSame("DATA QUERY FINGERTMP PIN=1024\tFID=3", $this->emitted($command));
    }

    /**
     * Separator pinned as a COUNT as well, the same way TemplateRoamingTest pins
     * `DATA UPDATE FINGERTMP` — so a future edit cannot quietly reintroduce a
     * space between two fields while leaving the field names intact.
     */
    public function test_query_fingertmp_uses_exactly_one_tab_and_one_space(): void
    {
        $emitted = $this->emitted($this->command('QUERY_FINGERTMP', ['pin' => '7', 'fid' => 0]));

        $this->assertSame(1, substr_count($emitted, "\t"), 'one tab: between PIN and FID');
        $this->assertSame(3, substr_count($emitted, ' '), 'three spaces: DATA QUERY FINGERTMP PIN=');
    }

    /**
     * `FID=0` is a real finger, not an absent one.
     *
     * The sibling `DELETE_FINGERTMP` omits FID when it is absent because
     * `FID=0` there would delete only the first finger when the caller asked for
     * all of them. The mirror-image mistake here would be treating a requested
     * finger 0 as "no finger given" and silently widening a one-finger query to
     * the whole person.
     */
    public function test_query_fingertmp_treats_finger_zero_as_a_real_finger(): void
    {
        $command = $this->command('QUERY_FINGERTMP', ['pin' => '1024', 'fid' => 0]);

        $this->assertSame("DATA QUERY FINGERTMP PIN=1024\tFID=0", $this->emitted($command));
    }

    /**
     * An FID with no PIN addresses nothing, so it degrades to the full dump
     * rather than emitting `DATA QUERY FINGERTMP FID=3` — a command whose
     * meaning we would be inventing, and which asks a device to guess whose
     * finger we meant.
     */
    public function test_query_fingertmp_ignores_a_finger_index_with_no_pin(): void
    {
        $command = $this->command('QUERY_FINGERTMP', ['fid' => 3]);

        $this->assertSame('DATA QUERY FINGERTMP', $this->emitted($command));
        $this->assertStringNotContainsString('FID=', $this->emitted($command));
    }

    /**
     * Empty-string payload values are absent values, not fields to emit.
     * `PIN=` on the wire is a syntax error, and a form post supplies '' where a
     * JSON caller supplies null.
     */
    public function test_query_fingertmp_omits_blank_payload_fields(): void
    {
        $this->assertSame(
            'DATA QUERY FINGERTMP',
            $this->emitted($this->command('QUERY_FINGERTMP', ['pin' => '', 'fid' => '']))
        );

        $this->assertSame(
            'DATA QUERY FINGERTMP PIN=1024',
            $this->emitted($this->command('QUERY_FINGERTMP', ['pin' => '1024', 'fid' => '']))
        );
    }

    /**
     * The command is registered as hardware-unverified, and it is NOT registered
     * as destructive.
     *
     * Both halves matter. Nothing on this path has been acked by a device, so a
     * -1002/-1004 must read as "our string, or this model" rather than as a
     * hardware fault. And a read-only query must never acquire a confirmation
     * gate meant for wipes — the point of this command is that it is cheap to
     * try, which is the entire reason it was preferred over the handshake.
     */
    public function test_query_fingertmp_is_declared_unverified_and_not_destructive(): void
    {
        $this->assertTrue(BiometricDeviceCommand::isHardwareUnverifiedType('QUERY_FINGERTMP'));
        $this->assertNotNull(BiometricDeviceCommand::hardwareUnverifiedReasonFor('QUERY_FINGERTMP'));
        $this->assertFalse(BiometricDeviceCommand::isDestructiveType('QUERY_FINGERTMP'));
        $this->assertFalse(BiometricDeviceCommand::isDeliberatelyUnimplementedType('QUERY_FINGERTMP'));
    }

    /**
     * Adding a case to the switch did not disturb its neighbours. `DATA QUERY
     * ATTLOG` in particular is one of the six hardware-verified strings and the
     * sole source of our tab evidence.
     */
    public function test_the_neighbouring_query_commands_are_unchanged(): void
    {
        $attlog = $this->command('CHECK_ATTLOG', [
            'start_time' => '2026-08-01 00:00:00',
            'end_time' => '2026-08-05 00:00:00',
        ]);

        $this->assertSame(
            "DATA QUERY ATTLOG StartTime=2026-08-01 00:00:00\tEndTime=2026-08-05 00:00:00",
            $this->emitted($attlog)
        );

        $this->assertSame(
            'DATA QUERY USERINFO PIN=1024',
            $this->emitted($this->command('QUERY_USERINFO', ['pin' => '1024']))
        );

        $this->assertSame(
            'DATA QUERY USERINFO',
            $this->emitted($this->command('QUERY_USERINFO'))
        );
    }

    // ──────────────────────────────────────────────────────────────
    //  2. Handshake regression guard — the live ingestion path
    // ──────────────────────────────────────────────────────────────

    /**
     * Every key a device currently needs is still in the handshake.
     *
     * This is the guard on the request that keeps a real business collecting
     * attendance. `buildHandshakeOptionsBody()` is the only thing standing
     * between a code change and a terminal that stops reporting punches, and the
     * failure is not loud — a device that stops being invited to push simply
     * goes quiet, and the first symptom is missing attendance days later.
     */
    public function test_the_handshake_still_carries_every_key_a_device_needs(): void
    {
        $device = $this->device();

        $body = $this->service()->buildHandshakeOptionsBody($device->serial_number);

        foreach ([
            "GET OPTION FROM: {$device->serial_number}",
            'ATTLOGStamp=',
            'OPERLOGStamp=9999',
            'errorDelay=30',
            'delay=10',
            'transTimes=00:00;14:05',
            'transFlag=',
            'encrypt=None',
            'ServerVer=2.4.1',
            'PushProtVer=',
        ] as $key) {
            $this->assertStringContainsString($key, $body, "handshake lost {$key}");
        }

        // CRLF-terminated, trailing blank line included: the device's parser
        // reads this as a line-oriented body and a lone \n has been seen to
        // truncate it on ZK firmware.
        $this->assertStringEndsWith("\r\n", $body);
        $this->assertStringNotContainsString('ATTPHOTOStamp', $body);
    }

    /**
     * `transFlag` is asserted UNCHANGED, and that is the assertion, not an
     * oversight in one.
     *
     * transFlag was the obvious lever for making the device push templates: the
     * leading ones plainly cover what we already receive, so a template bit
     * "must" be one of the zeros. The documentation falsifies that rather than
     * guiding it. The best-attested ordering is
     *
     *   1 attendance record · 2 operation log · 3 attendance photo ·
     *   4 enrolling a new fingerprint · 5 enrolling a new user · 6 fingerprint
     *   image · 7 changing user information · 8 changing a fingerprint ·
     *   9 new enrolled face · 10 user picture · 11 work code · 12 comparison photo
     *
     * under which digit 4 — enrolling a new fingerprint — is ALREADY 1 here. The
     * production MB460 has logged 13 fingerprint enrolments with that bit set
     * and pushed zero templates, so the single most plausible candidate is
     * already enabled and demonstrably insufficient. Sources also disagree on
     * the ordering (a named-token form puts EnrollFP at position 7) and on the
     * LENGTH (a distributor guide shows twelve digits; ours is ten).
     *
     * So the value is pinned exactly. Changing a digit here to chase templates
     * would risk switching off attendance collection for a live business on
     * evidence that does not support the change, and the acquisition problem is
     * solved by `DATA QUERY FINGERTMP` instead — where a wrong guess is rejected
     * by one device and changes nothing else. If a hardware probe ever settles
     * the ordering, this test is the thing to update, deliberately.
     */
    public function test_trans_flag_is_pinned_to_the_value_working_in_production(): void
    {
        $device = $this->device();

        $this->assertStringContainsString(
            'transFlag=1111000000',
            $this->service()->buildHandshakeOptionsBody($device->serial_number),
            'transFlag must not be changed without hardware evidence; see the docblock'
        );
    }

    /**
     * The handshake a real device receives over HTTP is byte-identical to what
     * the builder produces — so the guard above cannot be satisfied by a body
     * the endpoint never actually sends.
     */
    public function test_the_live_handshake_endpoint_returns_that_exact_body(): void
    {
        $device = $this->device();

        $response = $this->get('/iclock/cdata?SN='.rawurlencode($device->serial_number).'&options=all&pushver=2.4.1');

        $response->assertOk();
        $this->assertSame(
            $this->service()->buildHandshakeOptionsBody($device->serial_number, '2.4.1'),
            $response->getContent()
        );
    }

    // ──────────────────────────────────────────────────────────────
    //  3. The reply — a templatev10 push is parsed and stored
    // ──────────────────────────────────────────────────────────────

    /**
     * The shape we are actually trying to elicit: one push, one user, TWO
     * fingers.
     *
     * This is the real production shape — 26 fingerprints across 13 employees is
     * two each — and it is the case that used to collapse into a single row
     * holding the concatenation of the rest of the body. A query reply is a
     * multi-record push by definition, so if this does not hold, the command
     * above buys nothing.
     */
    public function test_a_two_finger_push_for_one_user_stores_two_rows(): void
    {
        $device = $this->device();
        $user = User::factory()->create(['employee_id' => 1024]);

        $result = $this->service()->processTemplateUpload(
            "PIN=1024\tFID=0\tSize=16\tValid=1\tTMP=VGh1bWJMZWZ0QUFB\r\n".
            "PIN=1024\tFID=1\tSize=16\tValid=1\tTMP=SW5kZXhSaWdodEJC\r\n",
            'templatev10',
            $device->serial_number,
            $device
        );

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['stored']);

        $rows = DB::table('biometric_templates')
            ->where('device_user_id', '1024')
            ->orderBy('finger_index')
            ->get();

        $this->assertCount(2, $rows, 'the second finger must not overwrite the first');
        $this->assertSame(0, (int) $rows[0]->finger_index);
        $this->assertSame(1, (int) $rows[1]->finger_index);

        // The templates are the ones sent, not each other and not a
        // concatenation of the body.
        $this->assertSame('VGh1bWJMZWZ0QUFB', $rows[0]->template_data);
        $this->assertSame('SW5kZXhSaWdodEJC', $rows[1]->template_data);

        foreach ($rows as $row) {
            $this->assertSame((string) $user->id, (string) $row->user_id);
            $this->assertSame('fingerprint', $row->template_type);
            $this->assertSame('templatev10', $row->template_version);
        }
    }

    /**
     * A multi-user dump — what the bare `DATA QUERY FINGERTMP` is expected to
     * return — keeps every person's fingers separate.
     */
    public function test_a_whole_device_dump_stores_every_person_and_every_finger(): void
    {
        $device = $this->device();
        $alice = User::factory()->create(['employee_id' => 2001]);
        $bob = User::factory()->create(['employee_id' => 2002]);

        $result = $this->service()->processTemplateUpload(
            "USERID=2001\tFID=0\tSize=8\tValid=1\tTMP=QUFBMDAwMA==\n".
            "USERID=2001\tFID=6\tSize=8\tValid=1\tTMP=QUFBMDAwMQ==\n".
            "USERID=2002\tFID=0\tSize=8\tValid=1\tTMP=QkJCMDAwMA==\n",
            'templatev10',
            $device->serial_number,
            $device
        );

        $this->assertTrue($result['success']);
        $this->assertSame(3, $result['stored']);

        $this->assertSame(2, DB::table('biometric_templates')->where('user_id', $alice->id)->count());
        $this->assertSame(1, DB::table('biometric_templates')->where('user_id', $bob->id)->count());

        $this->assertSame(
            'QUFBMDAwMQ==',
            DB::table('biometric_templates')
                ->where('device_user_id', '2001')->where('finger_index', 6)->value('template_data')
        );
    }

    /**
     * Both spellings of the identity field are accepted in one body.
     *
     * Firmware disagrees about whether a template push says `USERID=` or `PIN=`,
     * and a query reply is not guaranteed to use the same spelling as an
     * unprompted enrolment push. Rejecting one spelling would mean asking for
     * templates, receiving them, and storing nothing.
     */
    public function test_pin_and_userid_are_both_accepted_as_the_identity_field(): void
    {
        $device = $this->device();
        User::factory()->create(['employee_id' => 3001]);
        User::factory()->create(['employee_id' => 3002]);

        $result = $this->service()->processTemplateUpload(
            "PIN=3001\tFID=2\tTMP=UGluU3BlbGxpbmc=\n".
            "USERID=3002\tFID=2\tTMP=VXNlcmlkU3BlbGxn\n",
            'templatev10',
            $device->serial_number,
            $device
        );

        $this->assertSame(2, $result['stored']);
        $this->assertSame(
            'UGluU3BlbGxpbmc=',
            DB::table('biometric_templates')->where('device_user_id', '3001')->value('template_data')
        );
        $this->assertSame(
            'VXNlcmlkU3BlbGxn',
            DB::table('biometric_templates')->where('device_user_id', '3002')->value('template_data')
        );
    }

    /**
     * Descriptor fields are read in whatever order they arrive, and unknown
     * fields are ignored rather than breaking the record.
     *
     * `TMP` last is the only ordering assumed, because a base64 blob can contain
     * the literal text of any other key.
     */
    public function test_descriptor_fields_are_parsed_in_any_order(): void
    {
        $device = $this->device();
        User::factory()->create(['employee_id' => 4001]);

        $result = $this->service()->processTemplateUpload(
            "Size=12\tValid=1\tFID=9\tIndex=0\tDuress=0\tPIN=4001\tMajorVer=10\tTMP=T3V0T2ZPcmRlcg==\n",
            'templatev10',
            $device->serial_number,
            $device
        );

        $this->assertSame(1, $result['stored']);

        $row = DB::table('biometric_templates')->where('device_user_id', '4001')->first();
        $this->assertSame(9, (int) $row->finger_index);
        $this->assertSame('T3V0T2ZPcmRlcg==', $row->template_data);
    }

    /**
     * A re-query is idempotent per finger slot.
     *
     * `DATA QUERY FINGERTMP` is the kind of command an administrator will run
     * again whenever they are unsure the backup is current, and a device may
     * re-push on its own. Neither may duplicate rows, and the fresher template
     * must win.
     */
    public function test_running_the_query_twice_updates_rather_than_duplicates(): void
    {
        $device = $this->device();
        User::factory()->create(['employee_id' => 5001]);

        $body = fn (string $tmp) => "PIN=5001\tFID=4\tSize=12\tValid=1\tTMP={$tmp}\n";

        $this->service()->processTemplateUpload($body('T3JpZ2luYWxBQQ=='), 'templatev10', $device->serial_number, $device);
        $this->service()->processTemplateUpload($body('UmVlbnJvbGxlZEE='), 'templatev10', $device->serial_number, $device);

        $rows = DB::table('biometric_templates')->where('device_user_id', '5001')->get();

        $this->assertCount(1, $rows);
        $this->assertSame('UmVlbnJvbGxlZEE=', $rows[0]->template_data);
        $this->assertSame(4, (int) $rows[0]->finger_index);
    }

    /**
     * The reply reaching the real endpoint, over HTTP, with the query string a
     * device uses — and answered `OK`.
     *
     * The unit tests above call the parser directly; this one proves the route
     * actually hands a `table=templatev10` body to it. An ADMS device that gets
     * anything other than `OK` retries the same payload forever, so the answer
     * is as load-bearing as the storage.
     */
    public function test_a_template_push_over_http_is_stored_and_answered_ok(): void
    {
        $device = $this->device();
        User::factory()->create(['employee_id' => 6001]);

        $response = $this->call(
            'POST',
            '/iclock/cdata?SN='.rawurlencode($device->serial_number).'&table=templatev10&Stamp=9999',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain'],
            "PIN=6001\tFID=0\tSize=16\tValid=1\tTMP=T3ZlckhUVFBBQUFB\n".
            "PIN=6001\tFID=1\tSize=16\tValid=1\tTMP=T3ZlckhUVFBCQkJC\n"
        );

        $response->assertOk();
        $this->assertSame('OK', $response->getContent());

        $this->assertSame(
            2,
            DB::table('biometric_templates')
                ->where('biometric_device_id', $device->id)
                ->where('device_user_id', '6001')
                ->count()
        );
    }

    /**
     * A face template still lands in the no-finger slot rather than colliding
     * with somebody's finger 0.
     *
     * The device addresses a template by PIN + FID with no modality in the
     * address, so this is the guarantee that a face can never occupy a finger's
     * slot — and it stays true when the same person's fingers arrive from a
     * fingerprint query.
     */
    public function test_a_face_push_does_not_collide_with_finger_zero(): void
    {
        $device = $this->device();
        User::factory()->create(['employee_id' => 7001]);

        $this->service()->processTemplateUpload(
            "PIN=7001\tFID=0\tTMP=RmluZ2VyWmVybw==\n",
            'templatev10',
            $device->serial_number,
            $device
        );
        $this->service()->processTemplateUpload(
            "PIN=7001\tFID=0\tTMP=RmFjZVRlbXBsYXQ=\n",
            'facetmpv10',
            $device->serial_number,
            $device
        );

        $rows = DB::table('biometric_templates')
            ->where('device_user_id', '7001')
            ->orderBy('finger_index')
            ->get();

        $this->assertCount(2, $rows);
        $this->assertSame('face', $rows[0]->template_type);
        $this->assertSame(-1, (int) $rows[0]->finger_index);
        $this->assertSame('fingerprint', $rows[1]->template_type);
        $this->assertSame(0, (int) $rows[1]->finger_index);
    }

    /**
     * A reply for a PIN we have no employee for is skipped, and the push is
     * still answered as a success.
     *
     * A whole-device dump is exactly the case that returns templates for people
     * who are not in this system — a device roster is not our roster. The device
     * is behaving correctly and must not be made to retry a body we will never
     * accept, so the unmatched records are counted as skipped rather than turned
     * into a retry loop.
     */
    public function test_templates_for_unknown_pins_are_skipped_without_forcing_a_retry(): void
    {
        $device = $this->device();
        User::factory()->create(['employee_id' => 8001]);

        $result = $this->service()->processTemplateUpload(
            "PIN=8001\tFID=0\tTMP=S25vd25QZXJzb24=\n".
            "PIN=9999\tFID=0\tTMP=VW5rbm93blBpbg==\n",
            'templatev10',
            $device->serial_number,
            $device
        );

        $this->assertTrue($result['success'], 'the device must not be told to re-push');
        $this->assertSame(1, $result['stored']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(1, DB::table('biometric_templates')->count());
    }
}
