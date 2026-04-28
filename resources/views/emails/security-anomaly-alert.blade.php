<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Security Anomaly Alert</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
    <div style="max-width: 600px; margin: 0 auto; padding: 20px;">
        <div style="background: #f44336; padding: 20px; color: white; text-align: center; border-radius: 8px 8px 0 0;">
            <h1 style="margin: 0; font-size: 24px;">⚠️ Security Anomaly Detected</h1>
        </div>
        
        <div style="background: #fff; padding: 30px; border: 1px solid #ddd; border-top: none; border-radius: 0 0 8px 8px;">
            <p style="margin-top: 0;">A high-risk authentication anomaly has been detected in the system.</p>
            
            <h2 style="color: #f44336; border-bottom: 2px solid #f44336; padding-bottom: 10px;">Event Details</h2>
            <ul style="list-style: none; padding: 0;">
                <li style="padding: 8px 0; border-bottom: 1px solid #eee;"><strong>Email:</strong> {{ $data['event_data']['email'] ?? 'N/A' }}</li>
                <li style="padding: 8px 0; border-bottom: 1px solid #eee;"><strong>IP Address:</strong> {{ $data['event_data']['ip'] ?? 'N/A' }}</li>
                <li style="padding: 8px 0; border-bottom: 1px solid #eee;"><strong>Timestamp:</strong> {{ $data['timestamp']->format('Y-m-d H:i:s') }}</li>
                <li style="padding: 8px 0; border-bottom: 1px solid #eee;"><strong>Risk Score:</strong> {{ $data['analysis']['risk_score'] }}/100</li>
                <li style="padding: 8px 0; border-bottom: 1px solid #eee;"><strong>Risk Level:</strong> {{ strtoupper($data['analysis']['risk_level']) }}</li>
            </ul>

            <h2 style="color: #f44336; border-bottom: 2px solid #f44336; padding-bottom: 10px;">Risk Factors</h2>
            <ul style="list-style: none; padding: 0;">
                @foreach($data['analysis']['risk_factors'] as $factor)
                    <li style="padding: 8px 0; border-bottom: 1px solid #eee;">• {{ ucfirst(str_replace('_', ' ', $factor)) }}</li>
                @endforeach
            </ul>

            @if($data['analysis']['action_required'])
                <div style="background: #fff3cd; border: 1px solid #ffc107; padding: 15px; border-radius: 4px; margin-top: 20px;">
                    <p style="margin: 0; color: #856404;"><strong>Action Required:</strong> The account may have been automatically locked due to critical risk level. Please review the user's activity and take appropriate action.</p>
                </div>
            @endif

            <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee; text-align: center; color: #666; font-size: 12px;">
                <p style="margin: 0;">This is an automated security alert. If you believe this is in error, please contact your system administrator.</p>
            </div>
        </div>
    </div>
</body>
</html>
