# WhatsApp Automation for Gym CRM

Node.js service for automated WhatsApp reminders and inbound keyword replies.

## Stack
- Node.js
- `whatsapp-web.js`
- MySQL
- `node-cron`

## What it does
- 3-day membership expiry reminders
- Same-day expiry notices
- 14-day no-visit re-engagement
- Birthday wishes when a birthday column exists
- Monthly visit summaries
- Instant replies for `RENEW`, `SCHEDULE`, and `STATUS`

## Setup
1. Copy `.env.example` to `.env` and fill in DB + WhatsApp values.
2. Import `database/05_whatsapp_automation.sql` into the CRM database.
3. Install dependencies:
   ```bash
   cd whatsapp-bot
   npm install
   ```
4. Start the bot:
   ```bash
   npm start
   ```

## Important notes
- The bot reads member data from the CRM database only.
- Birthday wishes require one of these columns on a member table: `date_of_birth`, `dob`, `birthday`, `birth_date`.
- If no birthday column exists, the birthday job is skipped safely.
- Use `WHATSAPP_DRY_RUN=true` for safe local validation without sending messages.

## Run modes
- `WHATSAPP_MODE=daemon` default scheduler mode
- `WHATSAPP_MODE=daily` run daily campaigns once and exit
- `WHATSAPP_MODE=monthly` run monthly summary once and exit
- `WHATSAPP_MODE=queue` process queued outbound messages once and exit

## Inbound replies
- `RENEW` → renewal guidance + due snapshot
- `SCHEDULE` → gym timing
- `STATUS` → membership status + due + last visit
