{{-- Shared: items table + totals. Expects $proposal, $headBg, $headColor --}}
<table class="items" width="100%" cellspacing="0" cellpadding="0">
    <thead>
    <tr>
        <th style="background: {{ $headBg }}; color: {{ $headColor }}; width: 6%;">#</th>
        <th style="background: {{ $headBg }}; color: {{ $headColor }}; text-align:left;">Description</th>
        <th style="background: {{ $headBg }}; color: {{ $headColor }}; width: 10%;">Qty</th>
        <th style="background: {{ $headBg }}; color: {{ $headColor }}; width: 18%; text-align:right;">Price</th>
        <th style="background: {{ $headBg }}; color: {{ $headColor }}; width: 20%; text-align:right;">Amount</th>
    </tr>
    </thead>
    <tbody>
    @foreach($proposal->items ?? [] as $i => $item)
        <tr>
            <td style="text-align:center;">{{ $i + 1 }}</td>
            <td>{!! nl2br(e($item['description'])) !!}</td>
            <td style="text-align:center;">{{ rtrim(rtrim(number_format($item['qty'], 2), '0'), '.') }}</td>
            <td style="text-align:right;">{{ number_format($item['price'], 2) }}</td>
            <td style="text-align:right;">{{ number_format($item['qty'] * $item['price'], 2) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<table class="totals" cellspacing="0" cellpadding="0" align="right">
    <tr><td>Subtotal</td><td style="text-align:right;">{{ $proposal->money($proposal->subtotal) }}</td></tr>
    @if($proposal->discount > 0)
        <tr><td>Discount</td><td style="text-align:right;">- {{ $proposal->money($proposal->discount) }}</td></tr>
    @endif
    @if($proposal->tax_percent > 0)
        <tr><td>Tax ({{ rtrim(rtrim(number_format($proposal->tax_percent, 2), '0'), '.') }}%)</td><td style="text-align:right;">{{ $proposal->money($proposal->tax_amount) }}</td></tr>
    @endif
    <tr class="grand"><td style="background: {{ $headBg }}; color: {{ $headColor }};">Total</td><td style="text-align:right; background: {{ $headBg }}; color: {{ $headColor }};">{{ $proposal->money($proposal->total) }}</td></tr>
</table>
<div style="clear:both;"></div>
