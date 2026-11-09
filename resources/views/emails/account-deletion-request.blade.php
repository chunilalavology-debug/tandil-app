<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Deletion Request</title>
</head>
<body style="font-family: Arial, sans-serif; color: #111827; line-height: 1.5;">
    <h2 style="margin-bottom: 8px;">Account Deletion Request</h2>
    <p style="margin-top: 0;">Reference <strong>{{ $deletionRequest->reference }}</strong></p>

    <p><strong>Name:</strong> {{ $deletionRequest->full_name }}</p>
    <p><strong>Email:</strong> {{ $deletionRequest->email }}</p>
    <p><strong>Phone:</strong> {{ $deletionRequest->phone ?: '—' }}</p>
    <p><strong>Account type:</strong> {{ $deletionRequest->account_type }}</p>
    <p><strong>Matched user ID:</strong> {{ $deletionRequest->user_id ?: 'Not matched automatically' }}</p>
    <p><strong>Submitted at:</strong> {{ $deletionRequest->created_at }}</p>
    <p><strong>Request IP:</strong> {{ $deletionRequest->request_ip ?: '—' }}</p>

    <hr style="border: none; border-top: 1px solid #e5e7eb; margin: 16px 0;">

    <p style="margin-bottom: 6px;"><strong>Reason:</strong></p>
    <p style="white-space: pre-wrap;">{{ $deletionRequest->reason ?: 'No reason provided.' }}</p>

    <hr style="border: none; border-top: 1px solid #e5e7eb; margin: 16px 0;">

    <p style="font-size: 13px; color: #6b7280;">
        Verify the requester’s identity before deleting any account. Do not process requests that cannot be authenticated.
    </p>
</body>
</html>
