<?php
namespace App\Services\Hr;use App\Models\User;use Illuminate\Support\Facades\DB;
/**
 * HR reporting: access *control*. Maps a report kind to its gating
 * permission and answers whether a given user may view (and, separately,
 * export) a report of that kind for a company — including the active-staff
 * check that keeps a departed user's stale permissions from granting
 * access. Used to authorize requests before a run is queued and again when
 * a saved view/run is displayed; it has no involvement in building,
 * rendering, or storing report content (see {@see HrReportRunService},
 * {@see HrReportDatasetService}, {@see HrReportArtifactService}).
 */
class HrReportAccessService{
public function permission(string$kind):string{return match($kind){'analytics_snapshots'=>'hr.analytics.view','workforce_plans'=>'hr.workforce-planning.view','data_quality'=>'hr.data-quality.view',default=>'hr.reporting.view'};}
public function userAuthorized(string$userId,string$companyId,string$kind,bool$forExport=false):bool{$staff=DB::table('staff')->where('company_id',$companyId)->where('user_id',$userId)->whereNull('employment_ended_at')->whereNull('deleted_at')->exists();$user=User::query()->find($userId);return$staff&&$user&&$user->can($this->permission($kind))&&(!$forExport||$user->can('hr.reporting.export'));}
public function allowedKinds(User$user):array{return collect(['analytics_snapshots','workforce_plans','data_quality'])->filter(fn($kind)=>$user->can($this->permission($kind)))->values()->all();}}
