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
}
