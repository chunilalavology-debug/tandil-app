# Push notifications

iOS uses **Apple Push Notification service (APNs)** directly. Android uses **Firebase Cloud Messaging** when those keys are added later. Both include an alert payload so the banner shows while the app is in the background or closed.

## Mobile integration

After login (and whenever FCM refreshes the token):

```http
POST /api/auth/device-token
Authorization: Bearer {token}
Content-Type: application/json

{
  "token": "<device_push_token>",
  "platform": "ios"
}
```

Optional (analytics only — not required for push delivery): `device_id`, `app_version`, `locale`.

Alias: `POST /api/user/device-token` (same body).

On logout:

```http
DELETE /api/auth/device-token
Authorization: Bearer {token}
Content-Type: application/json

{ "token": "<fcm_device_token>" }
```

## Payload shape (background / killed)

Each push includes:

- `notification.title` / `notification.body` — shown by the OS tray when the app is not open
- `data.type` — event name (`new_paid_order`, `order_payment_confirmed`, `order_payment_updated`, `booking_confirmed`, `order_status_changed`, `subscription_booking_created`, …)
- `data.order_id`, `data.payment_status`, `data.order_status` when they apply
- Android `priority: HIGH` and APNs `apns-priority: 10` with an alert payload (not data-only), so Android and iOS display the notification while the React Native app is backgrounded or closed

### iOS — Expo (`ExponentPushToken[...]`)

Expo / React Native apps should register the token from `Notifications.getExpoPushTokenAsync()` (or legacy `ExponentPushToken[...]`) with `"platform": "ios"`. The backend sends via **Expo Push API** (`POST https://exp.host/--/api/v2/push/send`), not direct APNs.

```json
{
  "token": "ExponentPushToken[xxxxxxxxxxxxxx]",
  "platform": "ios"
}
```

When a **client** completes checkout and the order becomes **paid**, every **admin** user with tokens in `device_push_tokens` receives a database notification (`type: new_paid_order`) and an Expo push (title/body + `data.order_id`, etc.).

### iOS — native APNs (bare RN / no Expo)

After the app receives the **64-char hex** APNs device token, register it with `"platform": "ios"`. The server uses **APNs** (`.p8` in `.env`). Do not send `ExponentPushToken[...]` if you rely on native APNs.

### Android — Expo (recommended)

Use the same API as iOS. Register `Notifications.getExpoPushTokenAsync()` (or equivalent) with **`"platform": "android"`**:

```json
{
  "token": "ExponentPushToken[xxxxxxxxxxxxxxxxxxxxxx]",
  "platform": "android"
}
```

The backend sends via **Expo Push API** (`https://exp.host/--/api/v2/push/send`). **No** `FCM_PROJECT_ID` / `FCM_PRIVATE_KEY` on Laravel when all Android devices use Expo tokens; configure **FCM V1** in the [Expo dashboard](https://expo.dev) for the app.

The app must define a notification channel whose id matches server default **`tandil_default`** (or set `EXPO_ANDROID_CHANNEL_ID` in `.env`).

### Android — native FCM (optional, non-Expo builds)

Only when the app sends a raw FCM registration token (not `ExponentPushToken[...]`). Then set `FCM_*` service-account env vars on the server.

## Event coverage by role

| Event | Recipients |
|-------|------------|
| New paid order | Admin, area supervisors, area managers, vendors (product lines) |
| Payment confirmed / updated | Client (order), client (subscription) |
| Booking / appointment | Client (booking confirmed after paid service order); visit reminders |
| Order status change | Client (any `order_status` change, including visit/vendor sync) |
| Delivery OTP / shipped | Client + vendor (existing OTP notifications, now also FCM) |

## Server config

iOS (required now). Create the key in [Apple Developer → Keys](https://developer.apple.com/account/resources/authkeys/list) and enable **Apple Push Notifications service (APNs)**. Download the `.p8` once. Team ID is on the Membership page. Bundle ID is the iOS app id.

```env
APNS_ENABLED=true
APNS_DRIVER=auto
APNS_KEY_ID=
APNS_TEAM_ID=
APNS_BUNDLE_ID=
APNS_PRIVATE_KEY="-----BEGIN PRIVATE KEY-----\n...\n-----END PRIVATE KEY-----\n"
# true only for Xcode debug builds; App Store / TestFlight use production (false)
APNS_USE_SANDBOX=false
```

Expo (iOS + Android when using `ExponentPushToken[...]`):

```env
EXPO_PUSH_ENABLED=true
EXPO_PUSH_DRIVER=auto
# EXPO_ACCESS_TOKEN=
# EXPO_ANDROID_CHANNEL_ID=tandil_default
```

Android native FCM (only if not using Expo tokens):

```env
FCM_ENABLED=true
FCM_DRIVER=auto
FCM_PROJECT_ID=
FCM_CLIENT_EMAIL=
FCM_PRIVATE_KEY="-----BEGIN PRIVATE KEY-----\n...\n-----END PRIVATE KEY-----\n"
FCM_ANDROID_CHANNEL_ID=tandil_default
```

Admin setting `push_notifications_enabled` can disable all pushes without redeploying.

Without credentials, each driver falls back to `log` (safe for local/tests). iOS delivery does not wait on Firebase keys.
