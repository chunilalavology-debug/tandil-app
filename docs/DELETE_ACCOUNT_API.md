# Delete Account API (Apple Guideline 5.1.1 / Google Play Data Safety)

Mobile app **Profile → Delete Account** should show a confirmation dialog in the app, then call this API (no request body).

## Public web request page (Play Console)

Unauthenticated users can also request deletion at:

- `GET /delete-account` — public instructions + form (`https://app.tandil.ae/delete-account`)
- `POST /delete-account` — submit request (throttled; creates a pending `account_deletion_requests` row and emails support)

Web requests are **not** immediate deletions. Support verifies identity and processes within 30 days.
In-app client deletion via the authenticated API remains immediate.

## Endpoints

| Method | Path | Notes |
|--------|------|--------|
| `POST` | `/api/user/delete-account` | Recommended for mobile |
| `DELETE` | `/api/user/account` | Same handler |
| `POST` | `/api/auth/delete-account` | Alias (requires Bearer token) |
| `DELETE` | `/api/auth/account` | Alias |

**Auth:** `Authorization: Bearer {token}` (Sanctum)  
**Role:** `client` only  
**Body:** none — user is identified from the token only

## Request

```
POST /api/user/delete-account
Authorization: Bearer {token}
Accept: application/json
```

No JSON body, no `user_id` parameter.

## Success (200)

```json
{
  "success": true,
  "message": "Your account and personal data have been permanently deleted."
}
```

After success: clear local token/session and navigate to login/welcome.

## Errors

| Code | When |
|------|------|
| 401 | Missing/invalid token |
| 403 | Non-client role |

## What is deleted

- User record, profile photo, Sanctum tokens  
- Cart, saved addresses, payment methods, wallet credits, checkout session data  
- Notifications, subscriptions, complaints (DB cascades)  
- Orders are **kept** for business records but **unlinked** (`user_id` null); guest PII columns on those orders cleared  

## Profile settings discovery

`GET /api/client/settings/sections` includes:

```json
{
  "id": "delete_account",
  "title": "Delete Account",
  "path": "/api/user/delete-account",
  "method": "POST"
}
```

## App UI flow (for App Store review)

1. Login or register  
2. Profile → **Delete Account**  
3. In-app warning + confirm  
4. Call API (Bearer only) → logout → show logged-out state  
