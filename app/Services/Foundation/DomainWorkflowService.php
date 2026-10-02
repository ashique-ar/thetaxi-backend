<?php

namespace App\Services\Foundation;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class DomainWorkflowService
{
    public function start(
        string $domain,
        ?string $companyId,
        string $workflowType,
        string $subjectType,
        string $subjectId,
        string $initialState,
        ?string $actorUserId,
        string $idempotencyKey,
    ): object {
        return DB::transaction(function () use ($domain, $companyId, $workflowType, $subjectType, $subjectId, $initialState, $actorUserId, $idempotencyKey) {
            $existing = DB::table('domain_workflow_instances')
                ->where(compact('domain', 'workflow_type', 'subject_type', 'subject_id'))
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            $id = (string) Str::uuid();
            DB::table('domain_workflow_instances')->insert([
                'id' => $id,
                'domain' => $domain,
                'company_id' => $companyId,
                'workflow_type' => $workflowType,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'state' => $initialState,
                'lock_version' => 1,
                'initiated_by' => $actorUserId,
                'started_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('domain_workflow_transitions')->insert([
                'id' => (string) Str::uuid(),
                'workflow_instance_id' => $id,
                'sequence' => 1,
                'from_state' => null,
                'to_state' => $initialState,
                'action' => 'start',
                'idempotency_key' => $idempotencyKey,
                'actor_user_id' => $actorUserId,
                'transitioned_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return DB::table('domain_workflow_instances')->where('id', $id)->first();
        });
    }

    public function transition(
        string $workflowId,
        int $expectedLockVersion,
        string $expectedState,
        string $toState,
        string $action,
        string $idempotencyKey,
        ?string $actorUserId,
        ?string $reason = null,
        bool $complete = false,
    ): object {
        return DB::transaction(function () use ($workflowId, $expectedLockVersion, $expectedState, $toState, $action, $idempotencyKey, $actorUserId, $reason, $complete) {
            $workflow = DB::table('domain_workflow_instances')->where('id', $workflowId)->lockForUpdate()->first();
            if (! $workflow) {
                throw new RuntimeException('Workflow not found.');
            }

            $existing = DB::table('domain_workflow_transitions')
                ->where('workflow_instance_id', $workflowId)
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing) {
                return $workflow;
            }

            if ((int) $workflow->lock_version !== $expectedLockVersion || $workflow->state !== $expectedState) {
                throw new RuntimeException('Workflow state changed; refresh before retrying the transition.');
            }

            $sequence = DB::table('domain_workflow_transitions')->where('workflow_instance_id', $workflowId)->max('sequence') + 1;
            DB::table('domain_workflow_transitions')->insert([
                'id' => (string) Str::uuid(),
                'workflow_instance_id' => $workflowId,
                'sequence' => $sequence,
                'from_state' => $workflow->state,
                'to_state' => $toState,
                'action' => $action,
                'reason' => $reason,
                'idempotency_key' => $idempotencyKey,
                'actor_user_id' => $actorUserId,
                'transitioned_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('domain_workflow_instances')->where('id', $workflowId)->update([
                'state' => $toState,
                'lock_version' => $expectedLockVersion + 1,
                'completed_at' => $complete ? now() : null,
                'updated_at' => now(),
            ]);

            return DB::table('domain_workflow_instances')->where('id', $workflowId)->first();
        });
    }
}
