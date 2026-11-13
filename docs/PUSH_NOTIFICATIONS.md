# Push notifications

iOS uses **Apple Push Notification service (APNs)** directly. Android uses **Firebase Cloud Messaging** when those keys are added later. Both include an alert payload so the banner shows while the app is in the background or closed.

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

### iOS (APNs device token, not Firebase)

After the app receives the APNs device token, register it with `"platform": "ios"`:

```json
{
  "token": "<64-char hex APNs device token>",
  "platform": "ios"
}
```

React Native can read that token from `@react-native-community/push-notification-ios` (`register` event) or Expo `getDevicePushTokenAsync()`. The iOS app needs the Push Notifications capability and a provisioning profile that includes it. The server sends an `alert` with `apns-push-type: alert` and `apns-priority: 10`, which is what iOS displays when the app is backgrounded or closed.

### Android (later)

When Firebase is ready, send `messaging().getToken()` with `"platform": "android"`. Create a notification channel with id `tandil_default`.

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

Android (add later):

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
