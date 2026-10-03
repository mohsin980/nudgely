{{-- Estimate email body. Expects $message (plain text, escaped here), $document and $url. --}}
<div style="font-family:ui-sans-serif,system-ui,-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:14px;line-height:20px;color:#111827;">
    <p style="margin:0 0 16px 0;">{!! nl2br(e($message)) !!}</p>
    @if ($url)
        <p style="margin:0 0 24px 0;">
            <a href="{{ $url }}" style="display:inline-block;background:#4f46e5;color:#ffffff;text-decoration:none;font-weight:600;padding:10px 16px;border-radius:6px;">View, accept or decline your estimate</a>
        </p>
    @endif
    <div style="border:1px solid #e5e7eb;border-radius:8px;padding:20px;">
        @include('estimates.document', ['document' => $document])
    </div>
</div>
