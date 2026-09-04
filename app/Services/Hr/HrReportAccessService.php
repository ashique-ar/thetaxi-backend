<?php
namespace App\Services\Hr;use App\Models\User;use Illuminate\Support\Facades\DB;
class HrReportAccessService{
public function permission(string$kind):string{return match($kind){'analytics_snapshots'=>'hr.analytics.view','workforce_plans'=>'hr.workforce-planning.view','data_quality'=>'hr.data-quality.view',default=>'hr.reporting.view'};}
public function userAuthorized(string$userId,string$companyId,string$kind,bool$forExport=false):bool{$staff=DB::table('staff')->where('company_id',$companyId)->where('user_id',$userId)->whereNull('employment_ended_at')->whereNull('deleted_at')->exists();$user=User::query()->find($userId);return$staff&&$user&&$user->can($this->permission($kind))&&(!$forExport||$user->can('hr.reporting.export'));}
public function allowedKinds(User$user):array{return collect(['analytics_snapshots','workforce_plans','data_quality'])->filter(fn($kind)=>$user->can($this->permission($kind)))->values()->all();}}
