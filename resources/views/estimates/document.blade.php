{{-- The estimate itself. Inline styles so it renders the same in email clients and on the web. Expects $document (EstimateDocument). --}}
@php($e = $document->estimate)
@php($currency = $e->currency)
@php($muted = 'color:#4b5563;font-size:14px;line-height:20px;margin:0;')
<div style="font-family:ui-sans-serif,system-ui,-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#111827;max-width:640px;" data-document="estimate">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
        <tr>
            <td style="vertical-align:top;padding:0 0 16px 0;">
                <p style="font-size:18px;font-weight:600;margin:0;">{{ $document->organization->name }}</p>
                @if ($document->businessEmail)
                    <p style="{{ $muted }}">{{ $document->businessEmail }}</p>
                @endif
            </td>
            <td style="vertical-align:top;text-align:right;padding:0 0 16px 0;">
                <p style="font-size:12px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;color:#6b7280;margin:0;">Estimate</p>
                <p style="font-size:18px;font-weight:600;margin:0;">{{ $e->displayNumber() }}</p>
                <p style="{{ $muted }}">Date: {{ $document->date() }}</p>
                @if ($e->valid_until)
                    <p style="{{ $muted }}">Valid until: {{ $e->valid_until->format('F j, Y') }}</p>
                @endif
            </td>
        </tr>
    </table>

    <p style="font-size:12px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;color:#6b7280;margin:0;">Prepared for</p>
    <p style="font-size:14px;margin:0;">{{ $document->customer?->name }}</p>
    @if ($document->customer?->company)<p style="{{ $muted }}">{{ $document->customer->company }}</p>@endif
    <p style="{{ $muted }}">{{ $document->customer?->email }}</p>

    <p style="font-size:16px;font-weight:600;margin:20px 0 8px 0;">{{ $e->title }}</p>

    <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:14px;">
        <thead>
            <tr>
                <th align="left" style="padding:8px 0;border-bottom:1px solid #e5e7eb;color:#6b7280;font-weight:600;">Item</th>
                <th align="right" style="padding:8px 0;border-bottom:1px solid #e5e7eb;color:#6b7280;font-weight:600;">Amount</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($document->items as $item)
                <tr>
                    <td style="padding:8px 8px 8px 0;border-bottom:1px solid #f3f4f6;vertical-align:top;">
                        {{ $item->description }}
                        <br><span style="color:#6b7280;">{{ $item->displayQuantity() }} × {{ $item->money('unit_price', $currency) }}</span>
                    </td>
                    <td align="right" style="padding:8px 0;border-bottom:1px solid #f3f4f6;vertical-align:top;white-space:nowrap;">{{ $item->money('amount', $currency) }}</td>
                </tr>
            @empty
                <tr><td colspan="2" style="padding:8px 0;color:#6b7280;">No items yet.</td></tr>
            @endforelse
        </tbody>
    </table>

    <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:14px;margin-top:8px;">
        <tr><td align="right" style="padding:4px 12px 4px 0;color:#4b5563;">Subtotal</td><td align="right" style="padding:4px 0;white-space:nowrap;width:1%;">{{ $e->money('subtotal') }}</td></tr>
        <tr><td align="right" style="padding:4px 12px 4px 0;color:#4b5563;">Discount{{ $e->discount_type === \App\Enums\DiscountType::Percent ? ' ('.rtrim(rtrim((string) $e->discount_value, '0'), '.').'%)' : '' }}</td><td align="right" style="padding:4px 0;white-space:nowrap;">{{ $e->cents('discount_amount') ? '−'.$e->money('discount_amount') : $e->money('discount_amount') }}</td></tr>
        <tr><td align="right" style="padding:4px 12px 4px 0;color:#4b5563;">Tax{{ $e->tax_rate ? ' ('.rtrim(rtrim((string) $e->tax_rate, '0'), '.').'%)' : '' }}</td><td align="right" style="padding:4px 0;white-space:nowrap;">{{ $e->money('tax_amount') }}</td></tr>
        <tr><td align="right" style="padding:8px 12px 4px 0;font-size:16px;font-weight:600;border-top:1px solid #e5e7eb;">Total</td><td align="right" style="padding:8px 0 4px 0;font-size:18px;font-weight:700;white-space:nowrap;border-top:1px solid #e5e7eb;" data-total>{{ $e->money('total') }}</td></tr>
    </table>

    @if ($e->notes)
        <p style="font-size:12px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;color:#6b7280;margin:20px 0 4px 0;">Notes</p>
        <p style="font-size:14px;line-height:20px;margin:0;white-space:pre-line;">{{ $e->notes }}</p>
    @endif
</div>
