<?php
namespace Database\Seeders;
use Illuminate\Database\Seeder; use App\Models\Website\WebsiteSetting;
class TenantDecisionDefinitionSeeder extends Seeder { public function run(): void { WebsiteSetting::updateOrCreate(['type'=>'decision.catalog','company_id'=>null],['value'=>json_encode(['version'=>1,'keys'=>collect(config('tenant_decisions',[]))->pluck('key')->values()->all(),'note'=>'Templates are inactive until a company admin saves and approves a decision.'])]); } }
