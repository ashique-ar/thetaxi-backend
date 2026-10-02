<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Api\Hr\Concerns\AuthorizesAttendanceRequests;
use App\Http\Controllers\Api\Hr\Concerns\ManagesAttendanceDeviceCrud;
use App\Http\Controllers\Api\Hr\Concerns\ManagesAttendanceQuarantine;
use App\Http\Controllers\Api\Hr\Concerns\ManagesDeviceCredentials;
use App\Http\Controllers\Api\Hr\Concerns\ManagesDevicePeopleMapping;
use App\Http\Controllers\Api\Hr\Concerns\ManagesPhysicalAccessCommands;
use App\Http\Controllers\Controller;

/**
 * Hikvision-family attendance device CRUD, person-mapping, credential
 * management, quarantine review, and physical access-command workflows.
 *
 * See {@see \App\Http\Controllers\Api\Hr\HikvisionManagementController} for
 * the complementary device-lifecycle half (device groups, diagnostics,
 * time-config, safe-settings, credential rotation, alerts, and maintenance
 * approval) — this controller owns device CRUD, person-mapping, and
 * attendance sync.
 *
 * This class is a pure composition root: its public API (every method
 * signature, every route binding in routes/api.php) is unchanged from
 * before this file was split — the implementation now lives in concern
 * traits under Concerns/, grouped by responsibility:
 *
 * - {@see ManagesAttendanceDeviceCrud} — connector/device registration, identity
 *   probing, and manual/automatic sync.
 * - {@see ManagesDevicePeopleMapping} — terminal-person directory access and
 *   Staff-to-terminal identity mapping (single and bulk).
 * - {@see ManagesDeviceCredentials} — card/PIN credential lifecycle and identity
 *   disposition tagging.
 * - {@see ManagesAttendanceQuarantine} — raw event access and quarantine review/resolution.
 * - {@see ManagesPhysicalAccessCommands} — physical access-control command
 *   request/approval workflow.
 * - {@see AuthorizesAttendanceRequests} — shared legal-entity and feature-flag guards.
 */
class AttendanceDeviceController extends Controller
{
    use AuthorizesAttendanceRequests;
    use ManagesAttendanceDeviceCrud;
    use ManagesAttendanceQuarantine;
    use ManagesDeviceCredentials;
    use ManagesDevicePeopleMapping;
    use ManagesPhysicalAccessCommands;
}
