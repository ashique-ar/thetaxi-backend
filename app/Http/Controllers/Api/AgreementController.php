<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agreement;
use App\Models\AgreementActivity;
use App\Models\AgreementTemplate;
use App\Models\Document;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AgreementController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:agreements.view')->only(['index', 'show', 'stats', 'reports', 'exportReport', 'documents', 'pdf', 'timeline', 'bulkExport']);
        $this->middleware('permission:agreements.create')->only(['store', 'preview', 'duplicate', 'renew', 'uploadDocument']);
        $this->middleware('permission:agreements.edit')->only(['update', 'bulkUpdateStatus']);
        $this->middleware('permission:agreements.delete')->only(['destroy', 'bulkDelete']);
        $this->middleware('permission:agreement-templates.view')->only(['templates', 'showTemplate']);
        $this->middleware('permission:agreement-templates.create')->only(['storeTemplate']);
        $this->middleware('permission:agreement-templates.edit')->only(['updateTemplate']);
        $this->middleware('permission:agreement-templates.delete')->only(['destroyTemplate']);
    }

    public function index(Request $request): JsonResponse
    {
        $query = Agreement::query()->latest();
        $query->when($request->filled('search'), fn ($q) => $q->where(fn ($x) => $x->where('title', 'like', '%'.$request->string('search').'%')->orWhere('description', 'like', '%'.$request->string('search').'%')));
        foreach (['status', 'type', 'priority'] as $filter) $query->when($request->filled($filter), fn ($q) => $q->where($filter, $request->input($filter)));
        return response()->json($request->filled('page') ? $query->paginate($request->integer('per_page', 20)) : $query->get());
    }

    public function show(Agreement $agreement): JsonResponse { return response()->json($agreement); }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatedAgreement($request);
        $agreement = DB::transaction(function () use ($data, $request) {
            $agreement = Agreement::create($this->normalise($data));
            $this->activity($agreement, 'created', 'Agreement created.', $request);
            return $agreement;
        });
        return response()->json($agreement, Response::HTTP_CREATED);
    }

    public function update(Request $request, Agreement $agreement): JsonResponse
    {
        $agreement->update($this->normalise($this->validatedAgreement($request, true)));
        $this->activity($agreement, 'updated', 'Agreement updated.', $request);
        return response()->json($agreement->fresh());
    }

    public function destroy(Agreement $agreement): JsonResponse { $agreement->delete(); return response()->json(null, Response::HTTP_NO_CONTENT); }

    public function stats(): JsonResponse { return response()->json($this->statsData()); }
    public function reports(Request $request): JsonResponse
    {
        $this->validatedReportFilters($request);

        return response()->json([
            'stats' => $this->statsData(),
            'items' => $this->reportQuery($request)->latest()->get(),
        ]);
    }

    public function exportReport(Request $request): Response
    {
        $data = $this->validatedReportFilters($request, true);

        $agreements = $this->reportQuery($request)->latest()->get();
        $format = $data['format'] ?? 'csv';
        $filenameSuffix = now()->format('Ymd-His');

        if ($format === 'pdf') {
            return response($this->pdfBytes($agreements), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="agreement-report-'.$filenameSuffix.'.pdf"',
            ]);
        }

        return response($this->agreementCsv($agreements), 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="agreement-report-'.$filenameSuffix.'.csv"',
        ]);
    }

    public function templates(): JsonResponse { return response()->json(AgreementTemplate::latest()->get()); }
    public function showTemplate(AgreementTemplate $template): JsonResponse { return response()->json($template); }
    public function storeTemplate(Request $request): JsonResponse { return response()->json(AgreementTemplate::create($this->validatedTemplate($request)), Response::HTTP_CREATED); }
    public function updateTemplate(Request $request, AgreementTemplate $template): JsonResponse { $template->update($this->validatedTemplate($request, true)); return response()->json($template->fresh()); }
    public function destroyTemplate(AgreementTemplate $template): JsonResponse { $template->delete(); return response()->json(null, Response::HTTP_NO_CONTENT); }

    public function timeline(Agreement $agreement): JsonResponse { return response()->json($agreement->activities()->get()->map(fn ($a) => ['id'=>$a->id,'agreementId'=>$a->agreement_id,'type'=>$a->type,'description'=>$a->description,'userId'=>$a->user_id,'userName'=>'System','timestamp'=>$a->created_at,'metadata'=>$a->metadata])); }
    public function documents(Agreement $agreement): JsonResponse { return response()->json($agreement->documents()->latest()->get()->map(fn ($document) => $this->documentPayload($document))); }

    public function uploadDocument(Request $request, Agreement $agreement): JsonResponse
    {
        $data = $request->validate(['file' => 'required|file|max:20480', 'type' => 'nullable|string|max:40']);
        $file = $data['file'];
        $disk = $this->storageDisk();
        $path = $file->store("agreements/{$agreement->id}/documents", $disk);
        $document = $agreement->documents()->create([
            'document_type' => $data['type'] ?? 'attachment',
            'document_number' => (string) Str::uuid(),
            'disk' => $disk,
            'path' => $path,
            'file_name' => $file->getClientOriginalName(),
            'file_size' => $file->getSize(),
            'file_type' => $file->getMimeType(),
            'status' => 'verified',
            'verified_at' => now(),
            'verified_by' => $request->user()?->id,
            'created_user_id' => $request->user()?->id,
        ]);
        $this->activity($agreement, 'updated', 'Agreement document uploaded.', $request);
        return response()->json($this->documentPayload($document), Response::HTTP_CREATED);
    }

    public function downloadDocument(Document $document): mixed
    {
        $this->abortUnlessAgreementDocument($document);
        abort_unless(Storage::disk($document->disk)->exists($document->path), Response::HTTP_NOT_FOUND);
        return Storage::disk($document->disk)->download($document->path, $document->file_name);
    }

    public function deleteDocument(Document $document): JsonResponse
    {
        $this->abortUnlessAgreementDocument($document);
        $document->delete();
        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    public function pdf(Agreement $agreement): Response
    {
        return response($this->pdfBytes([$agreement]), 200, ['Content-Type'=>'application/pdf','Content-Disposition'=>'attachment; filename="agreement-'.$agreement->id.'.pdf"']);
    }

    public function preview(Request $request): Response
    {
        $agreement = new Agreement($this->normalise($this->validatedAgreement($request, true)));
        $agreement->title = $agreement->title ?: 'Untitled agreement preview';
        $agreement->status = $agreement->status ?: 'draft';
        $agreement->signature_status = 'unsigned';

        return response($this->pdfBytes([$agreement]), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="agreement-preview.pdf"',
        ]);
    }

    public function bulkExport(Request $request): Response
    {
        $data=$request->validate(['agreementIds'=>'required|array','agreementIds.*'=>'uuid','format'=>'required|in:pdf,excel']);
        $agreements=Agreement::whereIn('id',$data['agreementIds'])->get();
        if ($data['format']==='excel') {
            return response($this->agreementCsv($agreements),200,['Content-Type'=>'text/csv','Content-Disposition'=>'attachment; filename="agreements.csv"']);
        }
        return response($this->pdfBytes($agreements),200,['Content-Type'=>'application/pdf','Content-Disposition'=>'attachment; filename="agreements.pdf"']);
    }

    public function duplicate(Request $request, Agreement $agreement): JsonResponse
    {
        $copy = $agreement->replicate(['signature_status']); $copy->title .= ' (Copy)'; $copy->status = 'draft'; $copy->signature_status = 'unsigned'; $copy->save();
        $this->activity($copy, 'created', 'Agreement duplicated.', $request);
        return response()->json($copy, Response::HTTP_CREATED);
    }

    public function renew(Request $request, Agreement $agreement): JsonResponse
    {
        $days = $request->integer('renewal_period', $agreement->renewal_period ?: 365);
        $agreement->update(['status'=>'active','start_date'=>now(),'end_date'=>now()->addDays($days),'renewal_period'=>$days]);
        $this->activity($agreement, 'updated', 'Agreement renewed.', $request);
        return response()->json($agreement->fresh());
    }

    public function bulkUpdateStatus(Request $request): JsonResponse { $data=$request->validate(['agreementIds'=>'required|array','agreementIds.*'=>'uuid','status'=>'required|string']); Agreement::whereIn('id',$data['agreementIds'])->update(['status'=>strtolower($data['status'])]); return response()->json(['updated'=>count($data['agreementIds'])]); }
    public function bulkDelete(Request $request): JsonResponse { $data=$request->validate(['agreementIds'=>'required|array','agreementIds.*'=>'uuid']); Agreement::whereIn('id',$data['agreementIds'])->delete(); return response()->json(['deleted'=>count($data['agreementIds'])]); }

    private function validatedAgreement(Request $request, bool $partial=false): array
    {
        $p=$partial?'sometimes':'required';
        return $request->validate(['title'=>"$p|string|max:255",'description'=>'nullable|string','templateId'=>'nullable|uuid','type'=>'nullable|string|max:50','priority'=>'nullable|string|max:30','status'=>'nullable|string|max:30','content'=>'nullable|string','parties'=>'nullable|array','terms'=>'nullable','startDate'=>'nullable|date','endDate'=>'nullable|date|after_or_equal:startDate','autoRenew'=>'nullable|boolean','renewalPeriod'=>'nullable|integer|min:1']);
    }
    private function normalise(array $data): array { $map=['templateId'=>'template_id','startDate'=>'start_date','endDate'=>'end_date','autoRenew'=>'auto_renew','renewalPeriod'=>'renewal_period']; foreach($map as $from=>$to) if(array_key_exists($from,$data)){$data[$to]=$data[$from];unset($data[$from]);} if(isset($data['status']))$data['status']=strtolower($data['status']); return $data; }
    private function validatedTemplate(Request $request, bool $partial=false): array { $p=$partial?'sometimes':'required'; $d=$request->validate(['name'=>"$p|string|max:255",'type'=>"$p|string|max:50",'description'=>'nullable|string','content'=>"$p|string",'variables'=>'nullable|array','isActive'=>'nullable|boolean']); if(array_key_exists('isActive',$d)){$d['is_active']=$d['isActive'];unset($d['isActive']);} return $d; }
    private function validatedReportFilters(Request $request, bool $withFormat=false): array
    {
        $rules = [
            'reportType' => 'nullable|string|in:summary,expiry',
            'dateFrom' => 'nullable|date',
            'dateTo' => 'nullable|date|after_or_equal:dateFrom',
        ];
        if ($withFormat) {
            $rules['format'] = 'nullable|string|in:pdf,csv';
        }

        return $request->validate($rules);
    }
    private function statsData(): array { $total=Agreement::count(); $count=fn($s)=>Agreement::where('status',$s)->count(); $near=Agreement::whereBetween('end_date',[now(),now()->addDays(30)])->count(); return ['total'=>$total,'active'=>$count('active'),'expired'=>$count('expired'),'pending'=>$count('pending_signature'),'draft'=>$count('draft'),'cancelled'=>$count('cancelled'),'nearExpiry'=>$near,'total_agreements'=>$total,'active_agreements'=>$count('active'),'pending_signature'=>$count('pending_signature'),'expiring_soon'=>$near,'expired_agreements'=>$count('expired'),'draft_agreements'=>$count('draft')]; }
    private function reportQuery(Request $request)
    {
        return Agreement::query()
            ->when($request->input('reportType') === 'expiry', fn ($q) => $q->whereNotNull('end_date')->whereBetween('end_date', [now(), now()->addDays(90)]))
            ->when($request->filled('dateFrom'), fn ($q) => $q->whereDate('created_at', '>=', $request->date('dateFrom')))
            ->when($request->filled('dateTo'), fn ($q) => $q->whereDate('created_at', '<=', $request->date('dateTo')));
    }
    private function agreementCsv(iterable $agreements): string
    {
        $rows = collect($agreements)->map(fn($a)=>collect([$a->title,$a->type,$a->status,$a->start_date?->format('Y-m-d'),$a->end_date?->format('Y-m-d'),$a->signed_at?->format('Y-m-d H:i')])->map(fn($v)=>'"'.str_replace('"','""',(string)$v).'"')->implode(','));
        return "Title,Type,Status,Start Date,End Date,Signed At\n".$rows->implode("\n");
    }
    private function activity(Agreement $agreement,string $type,string $description,Request $request,array $metadata=[]): void { AgreementActivity::create(['agreement_id'=>$agreement->id,'type'=>$type,'description'=>$description,'metadata'=>$metadata ?: null,'user_id'=>$request->user()?->id]); }
    private function documentPayload(Document $d): array { return ['id'=>$d->id,'agreementId'=>$d->documentable_id,'name'=>$d->file_name,'type'=>$d->document_type,'url'=>$this->storageUrl($d->disk, $d->path),'size'=>$d->file_size,'uploadedAt'=>$d->created_at,'uploadedBy'=>$d->created_user_id]; }
    private function abortUnlessAgreementDocument(Document $document): void { abort_unless($document->documentable_type === Agreement::class, Response::HTTP_NOT_FOUND); }
    private function storageDisk(): string { return config('filesystems.default'); }
    private function storageUrl(string $diskName, string $path): string { $disk = Storage::disk($diskName); return method_exists($disk, 'providesTemporaryUrls') && $disk->providesTemporaryUrls() ? $disk->temporaryUrl($path, now()->addMinutes(15)) : $disk->url($path); }
    private function pdfBytes(iterable $agreements): string
    {
        $lines=[]; foreach($agreements as $a){$lines[]=$a->title; $lines[]='Status: '.$a->status.'  Type: '.($a->type ?: 'N/A'); $lines[]='Dates: '.($a->start_date?->format('Y-m-d') ?: 'N/A').' - '.($a->end_date?->format('Y-m-d') ?: 'Open'); if($a->signed_at)$lines[]='Signed at: '.$a->signed_at->format('Y-m-d H:i'); if(data_get($a->metadata,'identity_verified'))$lines[]='Identity verified: '.data_get($a->metadata,'identity_verification_method','yes'); foreach($a->signatures ?? [] as $signature){$lines[]='Signature: '.($signature['signature_method'] ?? 'typed').' at '.($signature['signed_at'] ?? 'N/A');} if($a->description)$lines[]=$a->description; if($a->parties)$lines[]='Parties: '.collect($a->parties)->map(fn($party)=>data_get($party,'name'))->filter()->implode(', '); if($a->terms){$lines[]='Terms:'; foreach(preg_split('/\R/', is_array($a->terms) ? json_encode($a->terms) : (string) $a->terms) as $termLine){if(trim($termLine)!=='')$lines[]=trim($termLine);}} if($a->content)$lines[]=$a->content; $lines[]='';}
        $text=collect($lines)->take(45)->map(fn($line)=>'('.str_replace(['\\','(',')'],['\\\\','\\(','\\)'],Str::limit($line,100,'')).') Tj 0 -16 Td')->implode("\n");
        $stream="BT /F1 11 Tf 50 790 Td\n{$text}\nET"; $objects=["<< /Type /Catalog /Pages 2 0 R >>","<< /Type /Pages /Kids [3 0 R] /Count 1 >>","<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 842] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>","<< /Length ".strlen($stream)." >>\nstream\n{$stream}\nendstream","<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>"];
        $pdf="%PDF-1.4\n";$offsets=[0];foreach($objects as $i=>$obj){$offsets[]=strlen($pdf);$n=$i+1;$pdf.="{$n} 0 obj\n{$obj}\nendobj\n";} $xref=strlen($pdf);$pdf.="xref\n0 6\n0000000000 65535 f \n";for($i=1;$i<=5;$i++)$pdf.=sprintf('%010d 00000 n ', $offsets[$i])."\n";return $pdf."trailer << /Size 6 /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
    }
}
