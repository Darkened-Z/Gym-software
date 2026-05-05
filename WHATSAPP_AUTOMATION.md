# WhatsApp Automation Layer

This repo now includes a standalone Node.js WhatsApp service in `whatsapp-bot/`.

## Install
```bash
cd whatsapp-bot
npm install
```

## Database
Import:
- `database/05_whatsapp_automation.sql`

## Start
```bash
npm start
```

## Safe local test
```bash
cd whatsapp-bot
WHATSAPP_DRY_RUN=true WHATSAPP_MODE=daily npm start
```

## Behavior
- expiry reminders 3 days before due date
- same-day expiry notices
- 14-day re-engagement
- birthday wishes when a DOB column exists
- monthly visit summaries
- inbound keyword replies: `RENEW`, `SCHEDULE`, `STATUS`
