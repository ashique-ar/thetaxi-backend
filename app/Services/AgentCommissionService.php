<?php

namespace App\Services;

use App\Models\Agent\Agent;
use App\Models\Agent\AgentCommission;
use App\Models\Booking\Booking;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AgentCommissionService
{
    // ─────────────────────────────────────────────────────────────────
    // Auto-calculate and record commission when a booking completes
    // ─────────────────────────────────────────────────────────────────

    /**
     * Record the commission for a completed booking.
     * Idempotent — no duplicate record is created for the same booking.
     */
    public function recordForBooking(Booking $booking): ?AgentCommission
    {
        if (!$booking->agent_id) {
            return null;
        }

        $already = AgentCommission::where('booking_id', $booking->id)->exists();
        if ($already) {
            return null;
        }

        $agent  = Agent::findOrFail($booking->agent_id);
        $amount = $this->calculateAmount($booking, $agent);

        if ($amount <= 0) {
            return null;
        }

        return AgentCommission::create([
            'agent_id'        => $agent->id,
            'booking_id'      => $booking->id,
            'amount'          => $amount,
            'paid'            => false,
            'created_user_id' => Auth::id(),
        ]);
    }

    /**
     * Calculate commission amount based on the agent's rate and the booking total.
     */
    public function calculateAmount(Booking $booking, Agent $agent): float
    {
        $total = (float) ($booking->total_actual ?? $booking->total_estimated ?? 0);
        $rate  = (float) ($agent->commission_rate ?? 0);

        if ($total <= 0 || $rate <= 0) {
            return 0.0;
        }

        return round($total * $rate / 100, 2);
    }

    // ─────────────────────────────────────────────────────────────────
    // Settlement run
    // ─────────────────────────────────────────────────────────────────

    /**
     * Run a settlement for all agents covering the given date range.
     * Marks all unpaid commissions for completed bookings in the range as paid.
     *
     * @return array{agent_id: string, agent_name: string, commissions_settled: int, total_amount: float}[]
     */
    public function runSettlement(Carbon $from, Carbon $to, ?string $agentId = null): array
    {
        $results = [];

        $agentQuery = Agent::query();
        if ($agentId) {
            $agentQuery->where('id', $agentId);
        }

        foreach ($agentQuery->get() as $agent) {
            $settled = $this->settleAgent($agent, $from, $to);

            if ($settled['commissions_settled'] > 0) {
                $results[] = $settled;
            }
        }

        Log::info('Commission settlement run completed', [
            'from'          => $from->toDateString(),
            'to'            => $to->toDateString(),
            'agents_settled' => count($results),
            'total_paid'    => array_sum(array_column($results, 'total_amount')),
        ]);

        return $results;
    }

    /**
     * Settle all unpaid commissions for a single agent within the date range.
     */
    public function settleAgent(Agent $agent, Carbon $from, Carbon $to): array
    {
        return DB::transaction(function () use ($agent, $from, $to) {
            $commissions = AgentCommission::where('agent_id', $agent->id)
                ->where('paid', false)
                ->whereHas('booking', fn ($q) => $q
                    ->where('status', 'completed')
                    ->whereBetween('completed_at', [$from->startOfDay(), $to->endOfDay()])
                )
                ->get();

            if ($commissions->isEmpty()) {
                return [
                    'agent_id'            => $agent->id,
                    'agent_name'          => $this->agentName($agent),
                    'commissions_settled' => 0,
                    'total_amount'        => 0.0,
                ];
            }

            $total = $commissions->sum('amount');

            AgentCommission::whereIn('id', $commissions->pluck('id'))
                ->update([
                    'paid'             => true,
                    'paid_at'          => now()->toDateString(),
                    'updated_user_id'  => Auth::id(),
                ]);

            return [
                'agent_id'            => $agent->id,
                'agent_name'          => $this->agentName($agent),
                'commissions_settled' => $commissions->count(),
                'total_amount'        => (float) $total,
            ];
        });
    }

    // ─────────────────────────────────────────────────────────────────
    // Statement / report
    // ─────────────────────────────────────────────────────────────────

    /**
     * Generate a statement of commissions for an agent over a date range.
     */
    public function getStatement(string $agentId, Carbon $from, Carbon $to): array
    {
        $agent = Agent::with('user')->findOrFail($agentId);

        $commissions = AgentCommission::with('booking:id,booking_number,completed_at,total_actual')
            ->where('agent_id', $agentId)
            ->whereHas('booking', fn ($q) => $q->whereBetween('completed_at', [$from->startOfDay(), $to->endOfDay()]))
            ->orderByDesc('created_at')
            ->get();

        $totalEarned = $commissions->sum('amount');
        $totalPaid   = $commissions->where('paid', true)->sum('amount');
        $totalUnpaid = $commissions->where('paid', false)->sum('amount');

        return [
            'agent'         => [
                'id'              => $agent->id,
                'name'            => $this->agentName($agent),
                'commission_rate' => $agent->commission_rate,
            ],
            'period'        => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'summary'       => [
                'total_commissions' => $commissions->count(),
                'total_earned'      => (float) $totalEarned,
                'total_paid'        => (float) $totalPaid,
                'total_unpaid'      => (float) $totalUnpaid,
            ],
            'commissions'   => $commissions->map(fn (AgentCommission $c) => [
                'id'             => $c->id,
                'booking_number' => $c->booking?->booking_number,
                'booking_amount' => $c->booking?->total_actual,
                'completed_at'   => $c->booking?->completed_at,
                'commission'     => $c->amount,
                'paid'           => $c->paid,
                'paid_at'        => $c->paid_at,
            ])->values(),
        ];
    }

    // ─────────────────────────────────────────────────────────────────
    // Helper
    // ─────────────────────────────────────────────────────────────────

    private function agentName(Agent $agent): string
    {
        $user = $agent->relationLoaded('user') ? $agent->user : $agent->user()->first();
        return $user ? trim("{$user->first_name} {$user->last_name}") : "Agent {$agent->code}";
    }
}
