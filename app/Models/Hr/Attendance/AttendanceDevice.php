<?php
namespace App\Models\Hr\Attendance;
use App\Models\NonSoftDeletableTrackedModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Support\LogOptions;
class AttendanceDevice extends NonSoftDeletableTrackedModel{protected $table='hr_attendance_devices';protected $fillable=['company_id','connector_id','organization_unit_id','provider','integration_mode','model','serial_number','firmware','site_code','timezone','capabilities','encrypted_configuration','status','last_sync_at','last_event_at','created_user_id'];protected $hidden=['encrypted_configuration'];protected $casts=['capabilities'=>'array','encrypted_configuration'=>'encrypted:array','last_sync_at'=>'datetime','last_event_at'=>'datetime'];public function getActivitylogOptions():LogOptions{return LogOptions::defaults()->logOnly(['company_id','provider','integration_mode','status'])->logOnlyDirty()->dontLogEmptyChanges()->dontLogIfAttributesChangedOnly(['updated_at','created_at'])->useLogName('AttendanceDevice');}public function connector():BelongsTo{return $this->belongsTo(AttendanceConnector::class,'connector_id');}}
