<?php

namespace App\Console\Commands;

use App\Models\Finance\FinancialAccountSettlement;
use App\Models\Finance\FinancialAuditEvent;
use App\Models\NotificationTemplate;
use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Mail;

class ProcessCorporateCollectionFollowUps extends Command
{
    protected $signature = 'corporate:process-collection-follow-ups {--dry-run}';
    protected $description = 'Notify assigned collectors about due corporate invoice follow-ups';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $today = today()->toDateString();
        $count = 0;

        FinancialAccountSettlement::query()
            ->where('owner_type', 'corporate')
            ->whereNotNull('collection_owner_id')
            ->whereNotNull('next_follow_up_at')
            ->where('next_follow_up_at', '<=', now())
            ->whereNotIn('status', ['paid', 'void', 'draft'])
            ->whereDoesntHave('auditEvents', fn ($query) => $query
                ->where('event_type', 'collection_follow_up_notified')
                ->whereDate('occurred_at', $today))
            ->chunkById(200, function ($settlements) use ($dryRun, $today, &$count): void {
                foreach ($settlements as $settlement) {
                    $count++;
                    if ($dryRun) {
                        continue;
                    }
                    DatabaseNotification::create([
                        'id' => (string) Str::uuid(),
                        'type' => 'App\\Notifications\\CorporateCollectionFollowUpNotice',
                        'notifiable_type' => 'App\\Models\\User',
                        'notifiable_id' => $settlement->collection_owner_id,
                        'data' => [
                            'notification_type' => 'corporate_collection_follow_up_due',
                            'settlement_id' => $settlement->id,
                            'invoice_number' => $settlement->invoice_number ?: $settlement->settlement_number,
                            'outstanding_total' => (float) $settlement->outstanding_total,
                            'promised_payment_date' => $settlement->promised_payment_date?->toDateString(),
                            'title' => 'Corporate collection follow-up due',
                            'message' => ($settlement->invoice_number ?: $settlement->settlement_number).' requires collection follow-up.',
                        ],
                    ]);
                    try {
                        $this->sendCustomerReminder($settlement);
                    } catch (\Throwable $exception) {
                        report($exception);
                        FinancialAuditEvent::create(['subject_type'=>'account_settlement','subject_id'=>$settlement->id,'event_type'=>'corporate_payment_reminder_failed','from_status'=>$settlement->status,'to_status'=>$settlement->status,'metadata'=>['error_class'=>get_class($exception)],'occurred_at'=>now()]);
                    }
                    FinancialAuditEvent::create([
                        'subject_type' => 'account_settlement',
                        'subject_id' => $settlement->id,
                        'event_type' => 'collection_follow_up_notified',
                        'from_status' => $settlement->status,
                        'to_status' => $settlement->status,
                        'performed_by' => $settlement->collection_owner_id,
                        'occurred_at' => now(),
                        'metadata' => ['due_date' => $today],
                    ]);
                }
            });

        $this->info("Processed {$count} corporate collection follow-up(s).");
        return self::SUCCESS;
    }

    private function sendCustomerReminder(FinancialAccountSettlement $settlement): void
    {
        if (!data_get($settlement->billing_terms_snapshot, 'delivery_preferences.email_payment_reminders', false)) return;
        $recipients = collect(data_get($settlement->billing_terms_snapshot, 'recipients', []))->filter()->values();
        $template = NotificationTemplate::query()->where('code', 'corporate_payment_reminder')->where('channel', 'email')->where('is_active', true)->first();
        if (!$template || $recipients->isEmpty()) throw new \RuntimeException('Corporate payment reminder template or recipients are not configured.');
        $variables = ['billing_name'=>data_get($settlement->billing_terms_snapshot, 'billing_name', 'Customer'),'invoice_number'=>$settlement->invoice_number ?: $settlement->settlement_number,'currency'=>data_get($settlement->billing_terms_snapshot, 'currency', 'LKR'),'outstanding_total'=>number_format((float)$settlement->outstanding_total, 2, '.', ','),'due_date'=>$settlement->due_date?->toDateString() ?: 'Not specified'];
        $render = fn (?string $value) => str_replace(collect($variables)->keys()->flatMap(fn ($key) => ["{{{$key}}}", "{{ {$key} }}"])->all(), collect($variables)->flatMap(fn ($value) => [$value, $value])->all(), $value ?? '');
        Mail::html($render($template->body), fn ($message) => $message->to($recipients->all())->subject($render($template->subject)));
        FinancialAuditEvent::create(['subject_type'=>'account_settlement','subject_id'=>$settlement->id,'event_type'=>'corporate_payment_reminder_sent','from_status'=>$settlement->status,'to_status'=>$settlement->status,'metadata'=>['sent_to'=>$recipients->all(),'template_code'=>$template->code],'occurred_at'=>now()]);
    }
}
