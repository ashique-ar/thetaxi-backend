# Transactional SMS Canary Runbook

This runbook is intentionally gated. Never install or start the canary worker merely because the code has been deployed.

## 1. Capture evidence

Run these read-only checks on the exact business deployment:

```bash
scripts/sms-production-service-audit.sh <existing-unit> <deployment-directory>
<verified-php-binary> artisan sms:audit-readiness --json
```

Stop if the audit reports historical SMS jobs, non-terminal SMS messages, incomplete schema, enabled event switches, disabled dry-run, scheduler-owned queue execution, or unwritable logs. Do not retry or release old jobs.

After schema deployment and configuration, enforce the guard in automation with:

```bash
<verified-php-binary> artisan sms:audit-readiness --json --require-ready
```

This exits non-zero when any Gate 1 blocker remains. The audit without `--require-ready` stays informational so pre-deployment evidence can still be captured.

## 2. Prepare the unit

Copy `deploy/systemd/thetaxi-sms-canary.service.example` to a new file and replace every placeholder using the captured target evidence. Do not copy values from another business.

Before installation, verify:

- `ExecStart` uses the target project-approved PHP binary;
- `WorkingDirectory` is the exact target deployment;
- `User` and `Group` own the target runtime files;
- the queue connection is the target application's configured connection;
- `--queue=sms` is present and no other queue is listed;
- `Restart=no` and no `WantedBy` activation exists;
- logs and cache are writable by the process user.

## 3. Dry-run acceptance

Keep the global transactional dry-run enabled and event switches off. Exercise one approved internal booking through confirmation, assignment, dispatch, arrival, start and completion using application requests. Then run:

```bash
<verified-php-binary> artisan sms:verify-booking <booking-id-or-number> --json
```

The verifier must pass before any provider canary. Replaying the lifecycle requests must not increase event counts.

## 4. Provider canary authorization

Provider activation is a separate, explicit operational decision. Immediately before it:

1. rerun `sms:audit-readiness --json`;
2. reconfirm that old SMS jobs are absent or explicitly revoked;
3. confirm the approved test recipient and cost;
4. keep inquiry, completion and fallback switches off;
5. activate only the dedicated `sms` worker;
6. enable confirmation first, followed by dispatch and arrival only after each previous event is verified;
7. inspect message status, callback identity, queue age, balance and logs after every step.

## 5. Stop and rollback

If submissions must stop, disable the affected event switch first and stop only the dedicated canary unit. Preserve SMS messages, booking activities and callback history. Do not roll back additive migrations while deployed code references them, and do not delete jobs until their exact identity and age are recorded.

The campaign queue is deliberately excluded. A transactional canary unit must never listen to `sms-campaigns`, `default`, `driver-notifications` or `customer-notifications`.
