<?php

namespace App\Console\Commands;

use App\Jobs\Foundation\RelayDomainOutboxEvent;
use App\Services\Foundation\DomainOutboxService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;

class PublishDomainOutboxEvents extends Command
{
    protected $signature = 'foundation:publish-outbox-events {--commit} {--limit=200}';

    protected $description = 'Relay due, unpublished domain outbox events onto the queue transport';

    public function handle(DomainOutboxService $outbox): int
    {
        if (! Schema::hasTable('domain_outbox_events')) {
            return self::SUCCESS;
        }

        $commit = (bool) $this->option('commit');
        $limit = max(1, (int) $this->option('limit'));

        if (! $commit) {
            $this->info('Would relay '.$outbox->countDuePublications().' due domain outbox event(s).');

            return self::SUCCESS;
        }

        $claimed = $outbox->claimDuePublications($limit);
        $relayed = 0;

        foreach ($claimed as $row) {
            try {
                RelayDomainOutboxEvent::dispatch($row->id)->onQueue('domain-outbox');
                $relayed++;
            } catch (Throwable $e) {
                $outbox->revertClaim($row->id, $e->getMessage());
            }
        }

        $this->info("Relayed {$relayed} of {$claimed->count()} claimed domain outbox event(s).");

        return self::SUCCESS;
    }
}
