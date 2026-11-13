# Push notifications (FCM)

TANDIL sends **Firebase Cloud Messaging** pushes so Android/iOS users receive alerts when the app is in the foreground, background, or fully closed.

## Mobile integration

After login (and whenever FCM refreshes the token):

```http
POST /api/auth/device-token
Authorization: Bearer {token}
Content-Type: application/json

{
  "token": "<fcm_device_token>",
  "platform": "android",
  "device_id": "optional-stable-device-id",
  "app_version": "1.2.3",
  "locale": "en"
}
```

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

React Native (`@react-native-firebase/messaging`): after login, read `messaging().getToken()` and `POST /api/auth/device-token`. Handle `onMessage` in the foreground, and read `remoteMessage.data` from `getInitialNotification()` / `onNotificationOpenedApp` when the user taps a tray notification. Create an Android notification channel with id `tandil_default`.

## Event coverage by role

| Event | Recipients |
|-------|------------|
| New paid order | Admin, area supervisors, area managers, vendors (product lines) |
| Payment confirmed / updated | Client (order), client (subscription) |
| Booking / appointment | Client (booking confirmed after paid service order); visit reminders |
| Order status change | Client (any `order_status` change, including visit/vendor sync) |
| Delivery OTP / shipped | Client + vendor (existing OTP notifications, now also FCM) |

## Server config

```env
FCM_ENABLED=true
FCM_DRIVER=auto
# Legacy (optional):
FCM_SERVER_KEY=
# HTTP v1 (preferred):
FCM_PROJECT_ID=
FCM_CLIENT_EMAIL=
FCM_PRIVATE_KEY="-----BEGIN PRIVATE KEY-----\n...\n-----END PRIVATE KEY-----\n"
FCM_ANDROID_CHANNEL_ID=tandil_default
```

Admin setting `push_notifications_enabled` can disable all pushes without redeploying.

Without credentials, driver falls back to `log` (safe for local/tests).
