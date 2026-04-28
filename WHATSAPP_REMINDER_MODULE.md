# WhatsApp Reminder Module

## Goal
Send fee and payment-related WhatsApp reminders to active gym members using consent-based business messaging.

## Allowed Use
- Fee due reminders
- Overdue fee reminders
- Renewal reminders
- Payment confirmations
- Operational member communication

## Rules
- Only message members with a valid business relationship
- Only message members with consent or a documented service basis
- Do not spam
- Log every attempt and outcome
- Allow revocation/opt-out handling in operations

## New Database Tables
1. `message_templates`
2. `member_consent`
3. `message_queue`
4. `message_logs`

SQL migration: `database/03_whatsapp_reminders.sql`

## Suggested Workflow
1. Find active members with due or overdue fees
2. Confirm consent status is `granted`
3. Pick template (`fee_due_basic`, `fee_overdue_basic`, `payment_confirmation_basic`)
4. Render variables:
   - `member_name`
   - `amount`
   - `due_date`
   - `payment_date`
   - `gym_name`
5. Insert pending row into `message_queue`
6. Worker sends WhatsApp message
7. Save delivery result in `message_logs`
8. Update queue status

## Selection Logic
### Fee due reminder
- member is active
- `next_fee_due_date` is today or within reminder window
- consent is granted
- no recent similar reminder in cooldown period

### Overdue reminder
- member is active
- `next_fee_due_date` is in the past
- `total_due_amount > 0`
- consent is granted

### Payment confirmation
- trigger after payment record is created
- consent is granted

## Recommended Cooldowns
- fee due: every 3 days max
- overdue: every 2 days max
- payment confirmation: immediate once

## Future API/Code Tasks
1. Add helper/service to render template variables
2. Add queue creation endpoint or cron job
3. Add delivery adapter for WhatsApp provider
4. Add admin UI:
   - template editor
   - consent status view
   - queue monitor
   - message logs
5. Add dashboard metrics:
   - reminders queued
   - reminders sent
   - overdue members reached
   - payment confirmations sent

## Suggested Next Files To Build
- `app/models/MessageTemplate.php`
- `app/models/MessageQueue.php`
- `app/models/MemberConsent.php`
- `api/reminders.php`
- `scripts/process_message_queue.php`

## Example Reminder
Assalam o Alaikum Ali,
Your gym fee of PKR 2500 is due on 2026-05-01.
Please pay on time to avoid service interruption.
Thanks - Goals Gym
