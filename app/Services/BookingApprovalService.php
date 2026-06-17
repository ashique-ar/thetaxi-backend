<?php

namespace App\Services;

use App\Models\Booking\Booking;
use App\Models\User;
use App\Services\Sms\SmsAutomationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class BookingApprovalService
{
    public function __construct(private SmsAutomationService $smsAutomation) {}

    /**
     * Process booking approval workflow
     */
    public function processBookingApproval(Booking $booking): array
    {
        // Determine if approval is needed based on business rules
        $approvalRequired = $this->isApprovalRequired($booking);
        
        if (!$approvalRequired) {
            // Auto-approve the booking
            return $this->autoApproveBooking($booking);
        }

        // Determine approval level required
        $approvalLevel = $this->determineApprovalLevel($booking);
        
        // Create approval request
        $approvalRequest = $this->createApprovalRequest($booking, $approvalLevel);
        
        // Notify approvers
        $this->notifyApprovers($approvalRequest);

        return [
            'requires_approval' => true,
            'approval_level' => $approvalLevel,
            'approval_request_id' => $approvalRequest['id'],
            'estimated_approval_time' => $this->getEstimatedApprovalTime($approvalLevel),
            'next_approvers' => $approvalRequest['approvers'],
        ];
    }

    /**
     * Determine if booking requires approval
     */
    private function isApprovalRequired(Booking $booking): bool
    {
        // Check various criteria that might require approval
        
        // High-value bookings
        if ($booking->total_estimated > 50000) { // LKR 50,000
            return true;
        }

        // Long-term bookings (more than 7 days)
        $duration = $booking->start_date->diffInDays($booking->end_date);
        if ($duration > 7) {
            return true;
        }

        // VIP customers might have special approval rules
        if ($booking->vip_id) {
            return true;
        }

        // Corporate bookings might need approval
        if ($booking->customer && $booking->customer->type === 'corporate') {
            return true;
        }

        // Peak season bookings
        if ($this->isPeakSeason($booking->start_date)) {
            return true;
        }

        // Last-minute bookings (within 24 hours)
        if ($booking->booking_date->diffInHours($booking->start_date) < 24) {
            return true;
        }

        return false;
    }

    /**
     * Determine the level of approval required
     */
    private function determineApprovalLevel(Booking $booking): string
    {
        // Very high value or special circumstances
        if ($booking->total_estimated > 200000 || $this->hasSpecialCircumstances($booking)) {
            return 'director';
        }

        // High value or VIP customers
        if ($booking->total_estimated > 100000 || $booking->vip_id) {
            return 'manager';
        }

        // Standard approval requirements
        return 'supervisor';
    }

    /**
     * Create approval request record
     */
    private function createApprovalRequest(Booking $booking, string $approvalLevel): array
    {
        $approvers = $this->getApprovers($approvalLevel);
        
        $approvalData = [
            'booking_id' => $booking->id,
            'approval_level' => $approvalLevel,
            'status' => 'pending',
            'requested_at' => now(),
            'requested_by' => $booking->created_user_id,
            'approval_reason' => $this->generateApprovalReason($booking),
            'approvers' => json_encode($approvers),
            'current_step' => 1,
            'total_steps' => count($approvers),
        ];

        $approvalId = DB::table('booking_approvals')->insertGetId($approvalData);
        
        $approvalData['id'] = $approvalId;
        $approvalData['approvers'] = $approvers;
        
        return $approvalData;
    }

    /**
     * Auto-approve a booking
     */
    private function autoApproveBooking(Booking $booking): array
    {
        $booking->update([
            'status' => 'confirmed',
            'confirmed' => true,
            'confirmed_at' => now(),
            'confirmed_by' => 'system',
        ]);

        // Create approval record for audit trail
        DB::table('booking_approvals')->insert([
            'booking_id' => $booking->id,
            'approval_level' => 'auto',
            'status' => 'approved',
            'requested_at' => now(),
            'approved_at' => now(),
            'approved_by' => 'system',
            'approval_reason' => 'Auto-approved: meets standard criteria',
        ]);

        $this->smsAutomation->queueBookingConfirmation($booking);

        return [
            'requires_approval' => false,
            'auto_approved' => true,
            'status' => 'confirmed',
        ];
    }

    /**
     * Get approvers for a specific level
     */
    private function getApprovers(string $approvalLevel): array
    {
        switch ($approvalLevel) {
            case 'supervisor':
                return User::whereHas('roles', function ($query) {
                    $query->where('name', 'supervisor');
                })->where('is_active', true)->get()->map(function ($user) {
                    return [
                        'user_id' => $user->id,
                        'name' => $user->first_name . ' ' . $user->last_name,
                        'email' => $user->email,
                        'role' => 'supervisor',
                        'step' => 1,
                    ];
                })->toArray();

            case 'manager':
                $approvers = [];
                
                // First step: Supervisor approval
                $supervisors = User::whereHas('roles', function ($query) {
                    $query->where('name', 'supervisor');
                })->where('is_active', true)->get();
                foreach ($supervisors as $supervisor) {
                    $approvers[] = [
                        'user_id' => $supervisor->id,
                        'name' => $supervisor->first_name . ' ' . $supervisor->last_name,
                        'email' => $supervisor->email,
                        'role' => 'supervisor',
                        'step' => 1,
                    ];
                }
                
                // Second step: Manager approval
                $managers = User::whereHas('roles', function ($query) {
                    $query->where('name', 'manager');
                })->where('is_active', true)->get();
                foreach ($managers as $manager) {
                    $approvers[] = [
                        'user_id' => $manager->id,
                        'name' => $manager->first_name . ' ' . $manager->last_name,
                        'email' => $manager->email,
                        'role' => 'manager',
                        'step' => 2,
                    ];
                }
                
                return $approvers;

            case 'director':
                // Three-step approval
                $approvers = [];
                
                // Step 1: Supervisor
                $supervisors = User::whereHas('roles', function ($query) {
                    $query->where('name', 'supervisor');
                })->where('is_active', true)->get();
                foreach ($supervisors as $supervisor) {
                    $approvers[] = [
                        'user_id' => $supervisor->id,
                        'name' => $supervisor->first_name . ' ' . $supervisor->last_name,
                        'email' => $supervisor->email,
                        'role' => 'supervisor',
                        'step' => 1,
                    ];
                }
                
                // Step 2: Manager
                $managers = User::whereHas('roles', function ($query) {
                    $query->where('name', 'manager');
                })->where('is_active', true)->get();
                foreach ($managers as $manager) {
                    $approvers[] = [
                        'user_id' => $manager->id,
                        'name' => $manager->first_name . ' ' . $manager->last_name,
                        'email' => $manager->email,
                        'role' => 'manager',
                        'step' => 2,
                    ];
                }
                
                // Step 3: Director
                $directors = User::whereHas('roles', function ($query) {
                    $query->where('name', 'director');
                })->where('is_active', true)->get();
                foreach ($directors as $director) {
                    $approvers[] = [
                        'user_id' => $director->id,
                        'name' => $director->first_name . ' ' . $director->last_name,
                        'email' => $director->email,
                        'role' => 'director',
                        'step' => 3,
                    ];
                }
                
                return $approvers;

            default:
                return [];
        }
    }

    /**
     * Generate approval reason
     */
    private function generateApprovalReason(Booking $booking): string
    {
        $reasons = [];

        if ($booking->total_estimated > 50000) {
            $reasons[] = "High value booking (LKR " . number_format($booking->total_estimated) . ")";
        }

        $duration = $booking->start_date->diffInDays($booking->end_date);
        if ($duration > 7) {
            $reasons[] = "Long-term booking ({$duration} days)";
        }

        if ($booking->vip_id) {
            $reasons[] = "VIP customer booking";
        }

        if ($booking->customer && $booking->customer->type === 'corporate') {
            $reasons[] = "Corporate customer booking";
        }

        if ($this->isPeakSeason($booking->start_date)) {
            $reasons[] = "Peak season booking";
        }

        if ($booking->booking_date->diffInHours($booking->start_date) < 24) {
            $reasons[] = "Last-minute booking (within 24 hours)";
        }

        return implode(', ', $reasons);
    }

    /**
     * Check if date falls in peak season
     */
    private function isPeakSeason(Carbon $date): bool
    {
        // Peak seasons in Sri Lanka
        $month = $date->month;
        
        // December-January (New Year season)
        if ($month === 12 || $month === 1) {
            return true;
        }
        
        // April (New Year season)
        if ($month === 4) {
            return true;
        }
        
        // July-August (vacation season)
        if ($month >= 7 && $month <= 8) {
            return true;
        }

        return false;
    }

    /**
     * Check for special circumstances
     */
    private function hasSpecialCircumstances(Booking $booking): bool
    {
        // Check for special requirements or unusual circumstances
        if (!empty($booking->special_requirements)) {
            return true;
        }

        // Check for unusual booking patterns
        // This could include checking customer booking history, unusual routes, etc.
        
        return false;
    }

    /**
     * Get estimated approval time
     */
    private function getEstimatedApprovalTime(string $approvalLevel): string
    {
        switch ($approvalLevel) {
            case 'supervisor':
                return '2-4 hours';
            case 'manager':
                return '4-8 hours';
            case 'director':
                return '8-24 hours';
            default:
                return 'Unknown';
        }
    }

    /**
     * Notify approvers
     */
    private function notifyApprovers(array $approvalRequest): void
    {
        // Get current step approvers
        $currentStepApprovers = collect($approvalRequest['approvers'])
            ->where('step', $approvalRequest['current_step']);

        foreach ($currentStepApprovers as $approver) {
            try {
                // Send notification (email, SMS, etc.)
                // This would integrate with your notification system
          
            } catch (\Exception $e) {
                Log::error('Failed to send approval notification', [
                    'booking_id' => $approvalRequest['booking_id'],
                    'approver_id' => $approver['user_id'],
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Process approval decision
     */
    public function processApprovalDecision(int $approvalId, string $decision, string $userId, string $comments = null): array
    {
        DB::beginTransaction();
        
        try {
            $approval = DB::table('booking_approvals')->where('id', $approvalId)->first();
            if (!$approval) {
                throw new \Exception('Approval request not found');
            }

            $booking = Booking::findOrFail($approval->booking_id);
            $approvers = json_decode($approval->approvers, true);
            
            // Record the decision
            DB::table('booking_approval_decisions')->insert([
                'booking_approval_id' => $approvalId,
                'user_id' => $userId,
                'decision' => $decision,
                'comments' => $comments,
                'step' => $approval->current_step,
                'decided_at' => now(),
            ]);

            if ($decision === 'rejected') {
                // Reject the booking
                DB::table('booking_approvals')->where('id', $approvalId)->update([
                    'status' => 'rejected',
                    'rejected_at' => now(),
                    'rejected_by' => $userId,
                    'rejection_reason' => $comments,
                ]);

                $booking->update([
                    'status' => 'rejected',
                    'rejected_at' => now(),
                    'rejected_by' => $userId,
                ]);

                DB::commit();
                
                return [
                    'status' => 'rejected',
                    'final_decision' => true,
                ];
            }

            // Check if this completes the current step
            $currentStepApprovers = collect($approvers)->where('step', $approval->current_step);
            $currentStepDecisions = DB::table('booking_approval_decisions')
                ->where('booking_approval_id', $approvalId)
                ->where('step', $approval->current_step)
                ->count();

            if ($currentStepDecisions >= $currentStepApprovers->count()) {
                // Current step is complete, move to next step or finalize
                if ($approval->current_step >= $approval->total_steps) {
                    // All steps complete - approve the booking
                    DB::table('booking_approvals')->where('id', $approvalId)->update([
                        'status' => 'approved',
                        'approved_at' => now(),
                        'approved_by' => $userId,
                    ]);

                    $booking->update([
                        'status' => 'confirmed',
                        'confirmed' => true,
                        'confirmed_at' => now(),
                        'confirmed_by' => $userId,
                    ]);

                    $this->smsAutomation->queueBookingConfirmation($booking);

                    DB::commit();

                    return [
                        'status' => 'approved',
                        'final_decision' => true,
                    ];
                } else {
                    // Move to next step
                    $nextStep = $approval->current_step + 1;
                    DB::table('booking_approvals')->where('id', $approvalId)->update([
                        'current_step' => $nextStep,
                    ]);

                    // Notify next step approvers
                    $nextStepApprovers = collect($approvers)->where('step', $nextStep);
                    // ... notification logic

                    DB::commit();
                    
                    return [
                        'status' => 'pending',
                        'current_step' => $nextStep,
                        'final_decision' => false,
                    ];
                }
            }

            DB::commit();
            
            return [
                'status' => 'pending',
                'current_step' => $approval->current_step,
                'final_decision' => false,
                'awaiting_other_approvers' => true,
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Approval decision processing error: ' . $e->getMessage(), [
                'approval_id' => $approvalId,
                'user_id' => $userId,
                'decision' => $decision,
            ]);
            
            throw $e;
        }
    }

    /**
     * Get approval status for a booking
     */
    public function getApprovalStatus(string $bookingId): array
    {
        $approval = DB::table('booking_approvals')
            ->where('booking_id', $bookingId)
            ->first();

        if (!$approval) {
            return ['status' => 'not_required'];
        }

        $decisions = DB::table('booking_approval_decisions')
            ->where('booking_approval_id', $approval->id)
            ->get();

        return [
            'status' => $approval->status,
            'approval_level' => $approval->approval_level,
            'current_step' => $approval->current_step,
            'total_steps' => $approval->total_steps,
            'requested_at' => $approval->requested_at,
            'approval_reason' => $approval->approval_reason,
            'decisions' => $decisions->map(function ($decision) {
                return [
                    'step' => $decision->step,
                    'decision' => $decision->decision,
                    'comments' => $decision->comments,
                    'decided_at' => $decision->decided_at,
                    'user_id' => $decision->user_id,
                ];
            })->toArray(),
        ];
    }
}
