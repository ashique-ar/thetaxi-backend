<?php

namespace App\Contracts\Hr\Attendance;

use App\Models\Hr\Attendance\AttendanceDevice;

interface AttendanceProviderAdapter
{
    /** @return array{model:string,serial_number:string,firmware:string,manufacturer:string,device_type:string,capabilities:array<string,bool>} */
    public function discover(AttendanceDevice $device): array;

    /** @return array{events:array<int,array<string,mixed>>,total_matches:int,has_more:bool,next_position:int} */
    public function attendanceEvents(AttendanceDevice $device, \DateTimeInterface $from, \DateTimeInterface $to, int $position = 0, int $limit = 30): array;

    /** @return array{people:array<int,array<string,mixed>>,total_matches:int,has_more:bool,next_position:int} */
    public function people(AttendanceDevice $device, int $position = 0, int $limit = 30): array;

    /** @return array{employee_no:string,display_name:string,created:bool} */
    public function provisionPerson(AttendanceDevice $device, string $employeeNumber, string $displayName): array;

    /** @return array{employee_no:string,display_name:string,enabled:bool} */
    public function updatePerson(AttendanceDevice $device, string $employeeNumber, string $displayName, bool $enabled): array;

    /** Update employment access without changing the terminal-managed display name. */
    public function setPersonEnabled(AttendanceDevice $device, string $employeeNumber, bool $enabled): array;

    /** @return array<int,array{card_number:string,card_type:string}> */
    public function cards(AttendanceDevice $device, string $employeeNumber): array;

    public function cardOwner(AttendanceDevice $device, string $cardNumber): ?string;

    public function setCard(AttendanceDevice $device, string $employeeNumber, string $cardNumber, string $cardType): array;

    public function deleteCard(AttendanceDevice $device, string $cardNumber): array;

    public function setPin(AttendanceDevice $device, string $employeeNumber, string $pin): array;

    /** @return array{cards:bool,pin:bool} */
    public function credentialCapabilities(AttendanceDevice $device): array;

    public function accessCapabilities(AttendanceDevice $device): array;

    public function applyAccess(AttendanceDevice $device, string $employeeNumber, ?int $doorNo, ?int $planTemplateNo): array;

    public function timeFacts(AttendanceDevice $device): array;

    public function setTimeConfiguration(AttendanceDevice $device, string $mode, string $timezone, ?string $ntpHost): array;

    public function safeSettings(AttendanceDevice $device): array;

    public function setSafeSettings(AttendanceDevice $device, array $settings): array;

    public function capacityFacts(AttendanceDevice $device): array;

    public function maintenanceCapabilities(AttendanceDevice $device): array;

    public function reboot(AttendanceDevice $device): array;
}
