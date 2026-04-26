<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>New Contact Message</title>
</head>
<body>
    <h1>New Contact Message</h1>

    <table>
        <tr>
            <td><strong>Name:</strong></td>
            <td>{{ $contactMessage->name }}</td>
        </tr>
        <tr>
            <td><strong>Email:</strong></td>
            <td>{{ $contactMessage->email }}</td>
        </tr>
        @if ($contactMessage->phone)
        <tr>
            <td><strong>Phone:</strong></td>
            <td>{{ $contactMessage->phone }}</td>
        </tr>
        @endif
        <tr>
            <td><strong>Type:</strong></td>
            <td>{{ $contactMessage->type->value }}</td>
        </tr>
        <tr>
            <td><strong>Subject:</strong></td>
            <td>{{ $contactMessage->subject }}</td>
        </tr>
        <tr>
            <td><strong>Submitted By:</strong></td>
            <td>{{ $contactMessage->user->name }} ({{ $contactMessage->user->email }})</td>
        </tr>
        <tr>
            <td><strong>Submitted At:</strong></td>
            <td>{{ $contactMessage->created_at->format('Y-m-d H:i:s') }}</td>
        </tr>
    </table>

    <h2>Message</h2>
    <p>{{ $contactMessage->message }}</p>
</body>
</html>
