# Ecotech CRM AI Chat Tab

PHP chat interface for **Ecotech Windows & Doors** CRM — queries live data from `https://api.ecotechcrm.ca/Api` and stores per-user chat history in Firebase Firestore.

## Setup

```bash
composer install
cp .env.example .env   # add keys
php -S localhost:8080 router.php
```

### `.env` required values

| Variable | Example |
|----------|---------|
| `OPENAI_API_KEY` | Your OpenAI key |
| `CRM_API_BASE_URL` | `https://api.ecotechcrm.ca/Api` |
| `CRM_CLIENT_ID` | From Ecotech API team |
| `CRM_USERNAME` | CRM service user |
| `CRM_PASSWORD` | CRM service password |

### Firebase (auth + history)

```bash
npx -y firebase-tools@latest login
npx -y firebase-tools@latest deploy --only firestore,auth --project ecotechaicrm
```

1. In [Firebase Console → Authentication](https://console.firebase.google.com/project/ecotechaicrm/authentication/users), create users (email/password) for each CRM staff member.
2. Add `localhost` to **Authorized domains** if using Google sign-in later.

Firestore rules now require sign-in; each user only sees their own `chat_sessions` and `chat_messages`.

## CRM API alignment

The client matches your existing Ecotech API patterns:

- **Auth**: `POST /Api/Authenticate` (form `username`/`password`, header `X-Client-Id`) → `access_token`
- **Reads**: `GET` with query params (e.g. `GetSalespersonAvailability`, `GetSalespersonPerformance`)
- **Writes**: `POST` JSON with PascalCase fields (e.g. `InsertLead`, `AddLeadComment`)
- **Regions**: `YYZ`, `HAM`, `OTT`, `A` (all), etc.

Approved chat tools (all auto-enabled for every user; per-user ACL coming later):

- Core: `getOpportunity`, `insertLead`, `addLeadComment`
- Sales: `getSalespersonServiceArea`, `getSalespersonAvailability`, `getSalespersonPerformance`
- Pipeline: `getLeads`, `getAppointments`, `getFollowUpsDue`
- Canvassing: `getCanvassers`, `getSalesRabbitOrganizations`, `getCanvassingDoorKnocks`, `getCanvassingOpportunities`, `getCanvassingLeads`, `getCanvassingQuotedLeads`, `getCanvassingClients`, `getCanvassingBonus`, `getCanvassingPerformance`

## Firestore indexes

Composite indexes (in `firestore.indexes.json`):

- `chat_messages`: `sessionId` + `userId` + `createdAt`
- `chat_sessions`: `userId` + `updatedAt`

Single-field indexes are auto-created by Firestore — do not add them to `firestore.indexes.json` (causes deploy error 400).
