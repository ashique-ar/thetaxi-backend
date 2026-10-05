<?php
namespace App\Models\Hr\Attendance;
use App\Models\NonSoftDeletableTrackedModel;
use Spatie\Activitylog\Support\LogOptions;
class AttendanceConnector extends NonSoftDeletableTrackedModel{protected $table='hr_attendance_connectors';protected $fillable=['company_id','name','topology','connector_key','signing_secret','status','allowed_ip_cidrs','last_heartbeat_at','capabilities','created_user_id'];protected $hidden=['signing_secret'];protected $casts=['signing_secret'=>'encrypted','last_heartbeat_at'=>'datetime','capabilities'=>'array'];public function getActivitylogOptions():LogOptions{return LogOptions::defaults()->logOnly(['company_id','topology','status'])->logOnlyDirty()->dontLogEmptyChanges()->dontLogIfAttributesChangedOnly(['updated_at','created_at'])->useLogName('AttendanceConnector');}}
