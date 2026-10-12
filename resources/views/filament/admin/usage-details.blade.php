@php
    /** @var array<string, mixed> $row */
    /** @var array<string, array<string, int|null>> $activity */
    $fmt = fn ($v) => $v === null ? 'Unlimited' : number_format($v);
@endphp
<div class="space-y-6 text-sm">
    <div>
        <h3 class="font-semibold">Current plan period: counts against limits</h3>
        <p class="text-gray-500">
            {{ $row['plan'] }}{{ $row['subscription_status'] ? ' ('.$row['subscription_status'].')' : '' }} ·
            {{ $row['period_start']->setTimezone($row['timezone'])->format('M j, Y g:i A') }} to {{ $row['period_end']->setTimezone($row['timezone'])->format('M j, Y g:i A') }}
            ({{ $row['timezone'] }}).
            Customers, automations and team members are counted as they are right now; emails and estimates are counted inside this period.
        </p>
        <table class="mt-2 w-full text-left">
            <thead class="text-gray-500"><tr><th class="py-1">Measure</th><th>Used</th><th>Limit</th><th>Remaining</th><th>State</th></tr></thead>
            <tbody>
            @foreach (\App\Enums\Billing\LimitKey::cases() as $key)
                @php($l = $row['limits'][$key->value])
                <tr class="border-t border-gray-100">
                    <td class="py-1">{{ $key->label() }}</td>
                    <td>{{ number_format($l['used']) }}</td>
                    <td>{{ $fmt($l['limit']) }}</td>
                    <td>{{ $l['remaining'] === null ? '—' : number_format($l['remaining']) }}</td>
                    <td>{{ $l['state']->label() }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    <div>
        <h3 class="font-semibold">Activity over time</h3>
        <p class="text-gray-500">Counted from stored records with their timestamps. Past customer, automation and seat counts are not recorded, so no history is shown for them.</p>
        <table class="mt-2 w-full text-left">
            <thead class="text-gray-500"><tr><th class="py-1">Measure</th><th>This plan period</th><th>Last 30 days</th><th>Lifetime</th></tr></thead>
            <tbody>
            @foreach ($activity as $label => $counts)
                <tr class="border-t border-gray-100">
                    <td class="py-1">{{ $label }}</td>
                    <td>{{ number_format($counts['period']) }}</td>
                    <td>{{ number_format($counts['last_30_days']) }}</td>
                    <td>{{ number_format($counts['lifetime']) }}</td>
                </tr>
            @endforeach
            <tr class="border-t border-gray-100"><td class="py-1">Opportunities created</td><td colspan="3" class="text-gray-500">Unavailable: the application does not record opportunities.</td></tr>
            </tbody>
        </table>
    </div>
</div>
