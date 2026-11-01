<table width="100%" cellspacing="0" cellpadding="0" style="margin-top: 40px;">
    <tr>
        <td width="50%" style="vertical-align: bottom;">
            @if($brand['signature'])
                <img src="{{ $brand['signature'] }}" style="max-height: 70px; max-width: 200px;"><br>
            @else
                <div style="height: 60px;"></div>
            @endif
            <div style="border-top: 1px solid #9ca3af; width: 220px; padding-top: 5px;">
                <strong>{{ $brand['signer_name'] ?: $brand['company_name'] }}</strong><br>
                <span style="color:#6b7280;">{{ $brand['signer_title'] }}{{ $brand['signer_title'] ? ', ' : '' }}{{ $brand['company_name'] }}</span>
            </div>
        </td>
        <td width="50%" style="vertical-align: bottom; text-align: right;">
            <div style="height: 60px;"></div>
            <div style="border-top: 1px solid #9ca3af; width: 220px; padding-top: 5px; margin-left: auto; display: inline-block; text-align: left;">
                <strong>Accepted by</strong><br>
                <span style="color:#6b7280;">{{ $proposal->client_name }}{{ $proposal->client_company ? ', '.$proposal->client_company : '' }}</span>
            </div>
        </td>
    </tr>
</table>
