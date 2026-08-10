# Ecotech CRM AI Chat Tab

PHP chat interface for **Ecotech Windows & Doors** CRM — queries live data from `https://api.ecotechcrm.ca/Api` and stores per-user chat history in Firebase Firestore.

## Setup (local)

```bash
composer install
cp .env.example .env   # add keys
php -S localhost:8080 router.php
```

### `.env` / Render env vars

| Variable | Example |
|----------|---------|
| `OPENAI_API_KEY` | Your OpenAI key |
| `OPENAI_MODEL` | `gpt-4o-mini` |
| `CRM_API_BASE_URL` | `https://api.ecotechcrm.ca/Api` |
| `CRM_CLIENT_ID` | From Ecotech API team |
| `CRM_USERNAME` | CRM service user |
| `CRM_PASSWORD` | CRM service password |
| `CRM_API_TIMEOUT` | `60` |
| `OPENAI_TIMEOUT` | `120` |
| `APP_DEBUG` | `false` |

Never commit `.env`. On Render, set the same keys in the service **Environment** tab.

## Deploy on Render (for Ecotech testing)

Use **Starter** (or higher), not Free — long CRM reports can exceed Free’s ~30s request timeout.

### 1. Push this repo to GitHub

Make sure `Dockerfile` and `render.yaml` are on `main`, and `.env` is **not** in the repo.

### 2. Create the web service

**Option A — Blueprint**

1. [Render Dashboard](https://dashboard.render.com) → **New** → **Blueprint**
2. Connect `simran-kanda1/ecotechAIinCrm`
3. Apply `render.yaml`, then fill secret env vars (`OPENAI_API_KEY`, `CRM_CLIENT_ID`, `CRM_USERNAME`, `CRM_PASSWORD`)

**Option B — Manual**

1. **New** → **Web Service** → connect the GitHub repo
2. Runtime: **Docker**
3. Plan: **Starter**
4. Add the env vars from the table above

Deploy URL will look like `https://ecotech-ai-crm.onrender.com`.

### 3. Custom domain

1. Render service → **Settings** → **Custom Domains** → **Add**
2. Enter your domain (e.g. `ai.yourdomain.com`)
3. At your DNS host, add the **CNAME** Render shows (point hostname → `xxx.onrender.com`)
4. Wait for HTTPS to provision (Render handles certificates)

### 4. Firebase (chat history)

Firestore is already open for browser chat history. After you have a live URL:

1. [Firebase Console](https://console.firebase.google.com/project/ecotechaicrm) → **Authentication** → **Settings** → **Authorized domains**
2. Add your Render hostname and custom domain (needed if you turn on Firebase Auth later)

Optional local Firebase deploy:

```bash
npx -y firebase-tools@latest login
npx -y firebase-tools@latest deploy --only firestore --project ecotechaicrm
```

### 5. Share with Ecotech

Send them the HTTPS URL (Render or custom domain). The app calls Ecotech CRM + OpenAI from the server using your env credentials — testers only need the link in a browser.

## CRM API alignment

The client matches your existing Ecotech API patterns:

- **Auth**: `POST /Api/Authenticate` (form `username`/`password`, header `X-Client-Id`) → `access_token`
- **Reads**: `GET` with query params (e.g. `GetSalespersonAvailability`, `GetSalespersonPerformance`)
- **Writes**: `POST` JSON with PascalCase fields (e.g. `InsertLead`, `AddLeadComment`)
- **Regions**: `YYZ`, `HAM`, `OTT`, `A` (all), etc.

Approved chat tools (all auto-enabled for every user; per-user ACL coming later):

- Core: `getOpportunity`, `insertLead`, `addLeadComment`, `getEntityUrl`, `resolveEntityLinks`
- Sales: `getSalespersonServiceArea`, `getSalespersonAvailability`, `getSalespersonPerformance`
- Pipeline: `getLeads`, `getAppointments`, `getFollowUpsDue`
- Canvassing: `getCanvassers`, `getSalesRabbitOrganizations`, `getCanvassingDoorKnocks`, `getCanvassingOpportunities`, `getCanvassingLeads`, `getCanvassingQuotedLeads`, `getCanvassingClients`, `getCanvassingBonus`, `getCanvassingPerformance`
- Admin: `getUsers`, `getRoles`
- Reports: `createChart` (pie/bar/line), `createReport` (printable PDF tab)

## Firestore indexes

Composite indexes (in `firestore.indexes.json`):

- `chat_messages`: `sessionId` + `userId` + `createdAt`
- `chat_sessions`: `userId` + `updatedAt`

Single-field indexes are auto-created by Firestore — do not add them to `firestore.indexes.json` (causes deploy error 400).
