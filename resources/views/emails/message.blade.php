<!DOCTYPE html>
<html>
<body style="margin:0; padding:0; background:#F6FAF8; font-family:Arial, sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#F6FAF8; padding:24px 0;">
    <tr><td align="center">
        <table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff; border-radius:8px; overflow:hidden; max-width:600px;">
            <tr><td style="background:#F4FBF2; padding:22px 28px; color:{{ $brand['primary_color'] }}; font-family:Georgia, serif; font-size:22px;">
                {{ $brand['company_name'] }}
            </td></tr>
            <tr><td style="background:{{ $brand['accent_color'] }}; height:4px; line-height:4px; font-size:0;">&nbsp;</td></tr>
            <tr><td style="padding:28px; color:#1f2937; font-size:15px; line-height:1.6;">
                {!! nl2br(e($messageBody)) !!}
                @foreach($files as $f)
                    <p style="margin-top:18px; padding:12px 14px; background:#F4FBF2; border-radius:6px; font-size:14px;"><strong>{{ $f['name'] }}</strong> is attached to this email.</p>
                @endforeach
            </td></tr>
            <tr><td style="padding:16px 28px; background:#f9fafb; color:#6b7280; font-size:12px;">
                {{ $brand['company_name'] }}
                @if($brand['company_phone']) · {{ $brand['company_phone'] }}@endif
                @if($brand['company_website']) · {{ $brand['company_website'] }}@endif
            </td></tr>
        </table>
    </td></tr>
</table>
</body>
</html>
