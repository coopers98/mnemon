<x-filament-panels::page>
    @php($devices = $this->getDevices())

    <p class="text-sm text-gray-500 dark:text-gray-400">
        Every MCP call is recorded with the calling credential's name, so this is
        what has actually reached this instance — not what was configured.
        <strong>active</strong> means a call within {{ $this->getActiveDays() }} days,
        <strong>idle</strong> within {{ $this->getStaleDays() }},
        <strong>stale</strong> beyond that, and <strong>never seen</strong> means the
        device was provisioned but has never successfully connected.
    </p>

    @if ($devices->isEmpty())
        <div class="rounded-xl border border-dashed border-gray-300 p-6 text-center text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">
            Nothing has connected yet. Provision a device with
            <code>php artisan mnemon:device-client &lt;name&gt;</code>, then enrol it
            with <code>scripts/mnemon-authorize.sh</code>.
        </div>
    @else
        <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
            <table class="w-full text-left text-sm">
                <thead class="bg-gray-50 text-xs uppercase text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                    <tr>
                        <th class="px-4 py-3">Device</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3">Last seen</th>
                        <th class="px-4 py-3 text-right">Calls</th>
                        <th class="px-4 py-3 text-right">Tools used</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach ($devices as $device)
                        <tr>
                            <td class="px-4 py-3 font-medium">
                                <code>{{ $device['device'] }}</code>
                            </td>
                            <td class="px-4 py-3">
                                @php($status = $device['status'])
                                <span @class([
                                    'inline-flex rounded-md px-2 py-1 text-xs font-medium',
                                    'bg-success-50 text-success-700 dark:bg-success-400/10 dark:text-success-400' => $status === 'active',
                                    'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300' => $status === 'idle',
                                    'bg-warning-50 text-warning-700 dark:bg-warning-400/10 dark:text-warning-400' => $status === 'stale',
                                    'bg-danger-50 text-danger-700 dark:bg-danger-400/10 dark:text-danger-400' => in_array($status, ['never seen', 'revoked'], true),
                                ])>{{ $status }}</span>
                            </td>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-400">
                                @if ($device['last_seen'])
                                    {{ \Illuminate\Support\Carbon::parse($device['last_seen'])->diffForHumans() }}
                                    <span class="text-xs text-gray-400">
                                        ({{ \Illuminate\Support\Carbon::parse($device['last_seen'])->toDayDateTimeString() }})
                                    </span>
                                @else
                                    &mdash;
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ number_format($device['calls']) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ $device['tools'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-filament-panels::page>
