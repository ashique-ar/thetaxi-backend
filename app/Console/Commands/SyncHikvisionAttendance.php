<?php

namespace App\Console\Commands;

use App\Models\Hr\Attendance\AttendanceDevice;
use App\Services\Hr\Attendance\DirectAttendanceSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class SyncHikvisionAttendance extends Command
{
    protected $signature = 'hr:hikvision-sync {--device=} {--lookback-minutes=15} {--days=}';
    protected $description = 'Pull and reconcile attendance events from active direct-ISAPI Hikvision terminals';

    public function handle(DirectAttendanceSyncService $sync): int
    {
        
        $query=AttendanceDevice::query()->where('provider','hikvision')->where('integration_mode','direct_isapi')->where('status','active')->when($this->option('device'),fn($q,$id)=>$q->whereKey($id));
        $failed=false;
        foreach($query->cursor() as $device){
            $to=CarbonImmutable::now();
            if($this->option('days')){
                $from=$to->subDays(max(1,min(31,(int)$this->option('days'))));
            }else{
                $lookbackMinutes=max(5,min(1440,(int)$this->option('lookback-minutes')));
                $from=$to->subMinutes($lookbackMinutes);
                if($device->last_sync_at){
                    $watermark=CarbonImmutable::parse($device->last_sync_at)->subMinutes($lookbackMinutes);
                    if($watermark->lessThan($from))$from=$watermark;
                }
            }
            try{$result=$sync->sync($device,$from,$to,$this->option('days')?'reconciliation':'incremental');$this->info("{$device->site_code}: ".json_encode($result['counts']));}catch(\Throwable $e){report($e);$this->error("{$device->site_code}: sync failed ({$e->getMessage()})");$failed=true;}
        }
        return $failed?self::FAILURE:self::SUCCESS;
    }
}
