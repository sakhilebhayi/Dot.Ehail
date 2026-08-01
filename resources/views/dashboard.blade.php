<x-app-layout>

<div style="padding:2rem 2.5rem;max-width:1400px;">

    {{-- Page header --}}
    <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:2rem;flex-wrap:wrap;gap:1rem;">
        <div>
            <h1 style="font-family:'Syne',sans-serif;font-size:1.6rem;font-weight:800;color:#f4f4f5;margin:0 0 0.25rem;">
                Ehail Operations Dashboard
            </h1>
            <p style="font-size:0.8rem;color:#71717a;margin:0;">
                {{ now()->format('l, F j, Y \a\t g:i A') }}
            </p>
        </div>
        <div style="display:flex;align-items:center;gap:0.5rem;padding:0.45rem 1rem;background:rgba(8,145,178,0.12);border:1px solid rgba(8,145,178,0.3);border-radius:9999px;">
            @if($activeRides > 0)
            <div style="width:7px;height:7px;border-radius:9999px;background:#22c55e;animation:pulse 2s infinite;"></div>
            <span style="font-size:0.7rem;font-weight:700;color:#67e8f9;text-transform:uppercase;letter-spacing:0.12em;">{{ $activeRides }} Active</span>
            @else
            <div style="width:7px;height:7px;border-radius:9999px;background:#8d90a2;"></div>
            <span style="font-size:0.7rem;font-weight:700;color:#71717a;text-transform:uppercase;letter-spacing:0.12em;">No Active Rides</span>
            @endif
        </div>
    </div>

    {{-- KPI Cards — row 1 --}}
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;margin-bottom:1rem;">

        {{-- Total Rides --}}
        <div style="background:#141416;border:1px solid rgba(255,255,255,0.07);border-radius:12px;padding:1.25rem 1.5rem;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.75rem;">
                <span style="font-size:0.7rem;font-weight:700;color:#71717a;text-transform:uppercase;letter-spacing:0.12em;">Total Rides</span>
                <div style="width:32px;height:32px;border-radius:8px;background:rgba(99,102,241,0.15);display:flex;align-items:center;justify-content:center;">
                    <span class="material-symbols-rounded" style="font-size:16px;color:#818cf8;">local_taxi</span>
                </div>
            </div>
            <div style="font-family:'Syne',sans-serif;font-size:2rem;font-weight:800;color:#f4f4f5;line-height:1;">{{ number_format($totalRides) }}</div>
            <div style="font-size:0.7rem;color:#71717a;margin-top:0.35rem;">All time</div>
        </div>

        {{-- Active Now --}}
        <div style="background:#141416;border:1px solid rgba(34,197,94,0.2);border-radius:12px;padding:1.25rem 1.5rem;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.75rem;">
                <span style="font-size:0.7rem;font-weight:700;color:#71717a;text-transform:uppercase;letter-spacing:0.12em;">Active Now</span>
                <div style="width:32px;height:32px;border-radius:8px;background:rgba(34,197,94,0.12);display:flex;align-items:center;justify-content:center;position:relative;">
                    <span class="material-symbols-rounded" style="font-size:16px;color:#4ade80;">near_me</span>
                    @if($activeRides > 0)
                    <div style="position:absolute;top:5px;right:5px;width:6px;height:6px;border-radius:9999px;background:#22c55e;animation:pulse 2s infinite;"></div>
                    @endif
                </div>
            </div>
            <div style="font-family:'Syne',sans-serif;font-size:2rem;font-weight:800;color:#4ade80;line-height:1;">{{ number_format($activeRides) }}</div>
            <div style="font-size:0.7rem;color:#71717a;margin-top:0.35rem;">In progress right now</div>
        </div>

        {{-- Completed --}}
        <div style="background:#141416;border:1px solid rgba(255,255,255,0.07);border-radius:12px;padding:1.25rem 1.5rem;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.75rem;">
                <span style="font-size:0.7rem;font-weight:700;color:#71717a;text-transform:uppercase;letter-spacing:0.12em;">Completed</span>
                <div style="width:32px;height:32px;border-radius:8px;background:rgba(34,197,94,0.12);display:flex;align-items:center;justify-content:center;">
                    <span class="material-symbols-rounded" style="font-size:16px;color:#4ade80;">check_circle</span>
                </div>
            </div>
            <div style="font-family:'Syne',sans-serif;font-size:2rem;font-weight:800;color:#f4f4f5;line-height:1;">{{ number_format($completedRides) }}</div>
            <div style="font-size:0.7rem;color:#71717a;margin-top:0.35rem;">
                @if($totalRides > 0)
                    {{ number_format(($completedRides / $totalRides) * 100, 1) }}% completion rate
                @else
                    No rides yet
                @endif
            </div>
        </div>

    </div>

    {{-- KPI Cards — row 2 --}}
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;margin-bottom:2rem;">

        {{-- Total Drivers --}}
        <div style="background:#141416;border:1px solid rgba(255,255,255,0.07);border-radius:12px;padding:1.25rem 1.5rem;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.75rem;">
                <span style="font-size:0.7rem;font-weight:700;color:#71717a;text-transform:uppercase;letter-spacing:0.12em;">Total Drivers</span>
                <div style="width:32px;height:32px;border-radius:8px;background:rgba(139,92,246,0.15);display:flex;align-items:center;justify-content:center;">
                    <span class="material-symbols-rounded" style="font-size:16px;color:#a78bfa;">person_pin_circle</span>
                </div>
            </div>
            <div style="font-family:'Syne',sans-serif;font-size:2rem;font-weight:800;color:#f4f4f5;line-height:1;">{{ number_format($totalDrivers) }}</div>
            <div style="font-size:0.7rem;color:#71717a;margin-top:0.35rem;">{{ $approvedDrivers }} approved</div>
        </div>

        {{-- Available Now --}}
        <div style="background:#141416;border:1px solid rgba(8,145,178,0.2);border-radius:12px;padding:1.25rem 1.5rem;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.75rem;">
                <span style="font-size:0.7rem;font-weight:700;color:#71717a;text-transform:uppercase;letter-spacing:0.12em;">Available Now</span>
                <div style="width:32px;height:32px;border-radius:8px;background:rgba(8,145,178,0.12);display:flex;align-items:center;justify-content:center;">
                    <span class="material-symbols-rounded" style="font-size:16px;color:#67e8f9;">wifi_tethering</span>
                </div>
            </div>
            <div style="font-family:'Syne',sans-serif;font-size:2rem;font-weight:800;color:#67e8f9;line-height:1;">{{ number_format($availableDrivers) }}</div>
            <div style="font-size:0.7rem;color:#71717a;margin-top:0.35rem;">Online &amp; accepting rides</div>
        </div>

        {{-- Total Revenue --}}
        <div style="background:#141416;border:1px solid rgba(34,197,94,0.15);border-radius:12px;padding:1.25rem 1.5rem;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.75rem;">
                <span style="font-size:0.7rem;font-weight:700;color:#71717a;text-transform:uppercase;letter-spacing:0.12em;">Total Revenue</span>
                <div style="width:32px;height:32px;border-radius:8px;background:rgba(34,197,94,0.12);display:flex;align-items:center;justify-content:center;">
                    <span class="material-symbols-rounded" style="font-size:16px;color:#4ade80;">payments</span>
                </div>
            </div>
            <div style="font-family:'Syne',sans-serif;font-size:2rem;font-weight:800;color:#4ade80;line-height:1;">
                R {{ number_format((float) $totalRevenue, 2) }}
            </div>
            <div style="font-size:0.7rem;color:#71717a;margin-top:0.35rem;">From completed rides</div>
        </div>

    </div>

    {{-- Middle row: Status breakdown + Driver availability --}}
    <div style="display:grid;grid-template-columns:1fr 340px;gap:1.5rem;margin-bottom:2rem;align-items:start;">

        {{-- Ride status breakdown --}}
        <div style="background:#141416;border:1px solid rgba(255,255,255,0.07);border-radius:12px;padding:1.5rem;">
            <h3 style="font-family:'Syne',sans-serif;font-size:0.85rem;font-weight:700;color:#f4f4f5;margin:0 0 1.25rem;text-transform:uppercase;letter-spacing:0.08em;">Ride Status Breakdown</h3>
            @php
                $allStatuses = [
                    'requested'   => ['label' => 'Requested',   'color' => '#f59e0b', 'bg' => 'rgba(245,158,11,0.12)'],
                    'accepted'    => ['label' => 'Accepted',    'color' => '#60a5fa', 'bg' => 'rgba(96,165,250,0.12)'],
                    'en_route'    => ['label' => 'En Route',    'color' => '#818cf8', 'bg' => 'rgba(129,140,248,0.12)'],
                    'arrived'     => ['label' => 'Arrived',     'color' => '#a78bfa', 'bg' => 'rgba(167,139,250,0.12)'],
                    'in_progress' => ['label' => 'In Progress', 'color' => '#67e8f9', 'bg' => 'rgba(103,232,249,0.12)'],
                    'completed'   => ['label' => 'Completed',   'color' => '#4ade80', 'bg' => 'rgba(74,222,128,0.12)'],
                    'cancelled'   => ['label' => 'Cancelled',   'color' => '#f87171', 'bg' => 'rgba(248,113,113,0.12)'],
                ];
            @endphp
            <div style="display:flex;flex-wrap:wrap;gap:0.6rem;">
                @foreach($allStatuses as $key => $meta)
                @php $cnt = $statusCounts[$key] ?? 0; @endphp
                <div style="display:flex;align-items:center;gap:0.5rem;padding:0.45rem 0.85rem;background:{{ $meta['bg'] }};border:1px solid {{ $meta['color'] }}33;border-radius:9999px;">
                    <div style="width:6px;height:6px;border-radius:9999px;background:{{ $meta['color'] }};flex-shrink:0;"></div>
                    <span style="font-size:0.72rem;font-weight:600;color:{{ $meta['color'] }};font-family:'Syne',sans-serif;">{{ $meta['label'] }}</span>
                    <span style="font-size:0.72rem;font-weight:800;color:#f4f4f5;font-family:'Syne',sans-serif;">{{ $cnt }}</span>
                </div>
                @endforeach
            </div>

            @if($totalRides > 0)
            <div style="margin-top:1.25rem;">
                <div style="font-size:0.68rem;color:#71717a;margin-bottom:0.5rem;text-transform:uppercase;letter-spacing:0.1em;">Completion vs Cancellation</div>
                <div style="display:flex;height:6px;border-radius:9999px;overflow:hidden;background:rgba(67,70,86,0.3);">
                    @php
                        $compPct  = $totalRides > 0 ? ($completedRides / $totalRides) * 100 : 0;
                        $canPct   = $totalRides > 0 ? ($cancelledRides / $totalRides) * 100 : 0;
                        $otherPct = max(0, 100 - $compPct - $canPct);
                    @endphp
                    <div style="width:{{ $compPct }}%;background:#4ade80;transition:width 0.5s;"></div>
                    <div style="width:{{ $otherPct }}%;background:rgba(103,232,249,0.4);transition:width 0.5s;"></div>
                    <div style="width:{{ $canPct }}%;background:#f87171;transition:width 0.5s;"></div>
                </div>
                <div style="display:flex;gap:1.5rem;margin-top:0.5rem;">
                    <span style="font-size:0.65rem;color:#4ade80;">{{ number_format($compPct, 1) }}% completed</span>
                    <span style="font-size:0.65rem;color:#f87171;">{{ number_format($canPct, 1) }}% cancelled</span>
                </div>
            </div>
            @endif
        </div>

        {{-- Driver availability card --}}
        <div style="background:#141416;border:1px solid rgba(255,255,255,0.07);border-radius:12px;padding:1.5rem;">
            <h3 style="font-family:'Syne',sans-serif;font-size:0.85rem;font-weight:700;color:#f4f4f5;margin:0 0 1.25rem;text-transform:uppercase;letter-spacing:0.08em;">Driver Fleet</h3>

            @php
                $availPct  = $totalDrivers > 0 ? ($availableDrivers / $totalDrivers) * 100 : 0;
                $approvPct = $totalDrivers > 0 ? ($approvedDrivers / $totalDrivers) * 100 : 0;
            @endphp

            <div style="text-align:center;margin-bottom:1.25rem;">
                <div style="font-family:'Syne',sans-serif;font-size:2.75rem;font-weight:800;color:#67e8f9;line-height:1;">{{ $availableDrivers }}</div>
                <div style="font-size:0.72rem;color:#71717a;margin-top:0.25rem;">of {{ $totalDrivers }} drivers online</div>
            </div>

            <div style="margin-bottom:1rem;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.35rem;">
                    <span style="font-size:0.68rem;color:#71717a;">Online</span>
                    <span style="font-size:0.68rem;font-weight:700;color:#67e8f9;">{{ number_format($availPct, 0) }}%</span>
                </div>
                <div style="height:6px;border-radius:9999px;background:rgba(67,70,86,0.3);overflow:hidden;">
                    <div style="width:{{ $availPct }}%;height:100%;background:linear-gradient(90deg,#0891b2,#67e8f9);border-radius:9999px;transition:width 0.5s;"></div>
                </div>
            </div>

            <div>
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.35rem;">
                    <span style="font-size:0.68rem;color:#71717a;">Approved</span>
                    <span style="font-size:0.68rem;font-weight:700;color:#a78bfa;">{{ number_format($approvPct, 0) }}%</span>
                </div>
                <div style="height:6px;border-radius:9999px;background:rgba(67,70,86,0.3);overflow:hidden;">
                    <div style="width:{{ $approvPct }}%;height:100%;background:linear-gradient(90deg,#7c3aed,#a78bfa);border-radius:9999px;transition:width 0.5s;"></div>
                </div>
            </div>

            <div style="margin-top:1.25rem;padding-top:1rem;border-top:1px solid rgba(255,255,255,0.06);">
                <div style="display:flex;justify-content:space-between;">
                    <div style="text-align:center;">
                        <div style="font-family:'Syne',sans-serif;font-size:1.1rem;font-weight:800;color:#4ade80;">{{ $approvedDrivers }}</div>
                        <div style="font-size:0.62rem;color:#71717a;margin-top:0.1rem;">Approved</div>
                    </div>
                    <div style="text-align:center;">
                        <div style="font-family:'Syne',sans-serif;font-size:1.1rem;font-weight:800;color:#f59e0b;">{{ $totalDrivers - $approvedDrivers }}</div>
                        <div style="font-size:0.62rem;color:#71717a;margin-top:0.1rem;">Pending / Susp.</div>
                    </div>
                    <div style="text-align:center;">
                        <div style="font-family:'Syne',sans-serif;font-size:1.1rem;font-weight:800;color:#67e8f9;">{{ $availableDrivers }}</div>
                        <div style="font-size:0.62rem;color:#71717a;margin-top:0.1rem;">Online</div>
                    </div>
                </div>
            </div>
        </div>

    </div>

    {{-- Recent Rides table --}}
    <div style="background:#141416;border:1px solid rgba(255,255,255,0.07);border-radius:12px;overflow:hidden;">
        <div style="padding:1.25rem 1.5rem;border-bottom:1px solid rgba(255,255,255,0.06);display:flex;align-items:center;justify-content:space-between;">
            <h3 style="font-family:'Syne',sans-serif;font-size:0.85rem;font-weight:700;color:#f4f4f5;margin:0;text-transform:uppercase;letter-spacing:0.08em;">Recent Rides</h3>
            <a href="{{ route('rides.index') }}" style="font-size:0.68rem;color:#38bdf8;text-decoration:none;font-weight:600;">View all &amp; search →</a>
        </div>

        @if($recentRides->isEmpty())
        <div style="padding:3rem;text-align:center;color:#71717a;">
            <span class="material-symbols-rounded" style="font-size:48px;display:block;margin-bottom:0.75rem;opacity:0.3;">local_taxi</span>
            <p style="font-size:0.85rem;margin:0;">No rides recorded yet.</p>
        </div>
        @else
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;">
                <thead>
                    <tr style="border-bottom:1px solid rgba(255,255,255,0.06);">
                        <th style="padding:0.65rem 1.5rem;text-align:left;font-size:0.65rem;font-weight:700;color:#71717a;text-transform:uppercase;letter-spacing:0.12em;white-space:nowrap;">#</th>
                        <th style="padding:0.65rem 1rem;text-align:left;font-size:0.65rem;font-weight:700;color:#71717a;text-transform:uppercase;letter-spacing:0.12em;">Route</th>
                        <th style="padding:0.65rem 1rem;text-align:left;font-size:0.65rem;font-weight:700;color:#71717a;text-transform:uppercase;letter-spacing:0.12em;">Driver</th>
                        <th style="padding:0.65rem 1rem;text-align:left;font-size:0.65rem;font-weight:700;color:#71717a;text-transform:uppercase;letter-spacing:0.12em;">Passenger</th>
                        <th style="padding:0.65rem 1rem;text-align:left;font-size:0.65rem;font-weight:700;color:#71717a;text-transform:uppercase;letter-spacing:0.12em;">Status</th>
                        <th style="padding:0.65rem 1rem;text-align:right;font-size:0.65rem;font-weight:700;color:#71717a;text-transform:uppercase;letter-spacing:0.12em;">Fare</th>
                        <th style="padding:0.65rem 1.5rem;text-align:right;font-size:0.65rem;font-weight:700;color:#71717a;text-transform:uppercase;letter-spacing:0.12em;">Time</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($recentRides as $ride)
                    @php
                        $statusStyles = [
                            'requested'   => ['color' => '#f59e0b', 'bg' => 'rgba(245,158,11,0.12)', 'label' => 'Requested'],
                            'accepted'    => ['color' => '#60a5fa', 'bg' => 'rgba(96,165,250,0.12)',  'label' => 'Accepted'],
                            'en_route'    => ['color' => '#818cf8', 'bg' => 'rgba(129,140,248,0.12)', 'label' => 'En Route'],
                            'arrived'     => ['color' => '#a78bfa', 'bg' => 'rgba(167,139,250,0.12)', 'label' => 'Arrived'],
                            'in_progress' => ['color' => '#67e8f9', 'bg' => 'rgba(103,232,249,0.12)', 'label' => 'In Progress'],
                            'completed'   => ['color' => '#4ade80', 'bg' => 'rgba(74,222,128,0.12)',  'label' => 'Completed'],
                            'cancelled'   => ['color' => '#f87171', 'bg' => 'rgba(248,113,113,0.12)', 'label' => 'Cancelled'],
                        ];
                        $ss = $statusStyles[$ride->status] ?? ['color' => '#8d90a2', 'bg' => 'rgba(141,144,162,0.1)', 'label' => ucfirst($ride->status)];
                        $pickup   = strlen($ride->pickup_address)   > 28 ? substr($ride->pickup_address, 0, 28).'…'   : $ride->pickup_address;
                        $dropoff  = strlen($ride->dropoff_address)  > 28 ? substr($ride->dropoff_address, 0, 28).'…'  : $ride->dropoff_address;
                    @endphp
                    <tr onclick="window.location='{{ route('rides.show', $ride) }}'" style="cursor:pointer;border-bottom:1px solid rgba(67,70,86,0.1);transition:background 0.15s;" onmouseover="this.style.background='rgba(26,36,56,0.6)'" onmouseout="this.style.background='transparent'">
                        <td style="padding:0.85rem 1.5rem;font-size:0.75rem;font-weight:600;color:#71717a;font-family:'Syne',sans-serif;">#{{ $ride->id }}</td>
                        <td style="padding:0.85rem 1rem;max-width:220px;">
                            <div style="font-size:0.75rem;color:#f4f4f5;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $pickup }}</div>
                            <div style="display:flex;align-items:center;gap:0.3rem;margin-top:0.2rem;">
                                <span class="material-symbols-rounded" style="font-size:11px;color:#0891b2;">arrow_downward</span>
                                <span style="font-size:0.7rem;color:#71717a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $dropoff }}</span>
                            </div>
                        </td>
                        <td style="padding:0.85rem 1rem;">
                            @if($ride->driver)
                            <div style="display:flex;align-items:center;gap:0.5rem;">
                                <div style="width:26px;height:26px;border-radius:9999px;background:#0891b2;display:flex;align-items:center;justify-content:center;font-size:0.65rem;font-weight:700;color:#fff;flex-shrink:0;">
                                    {{ strtoupper(substr($ride->driver->name, 0, 1)) }}
                                </div>
                                <span style="font-size:0.75rem;color:#f4f4f5;font-weight:500;white-space:nowrap;">{{ $ride->driver->name }}</span>
                            </div>
                            @else
                            <span style="font-size:0.72rem;color:#71717a;font-style:italic;">Unassigned</span>
                            @endif
                        </td>
                        <td style="padding:0.85rem 1rem;">
                            <span style="font-size:0.75rem;color:#f4f4f5;font-weight:500;">{{ $ride->passenger?->name ?? '—' }}</span>
                        </td>
                        <td style="padding:0.85rem 1rem;">
                            <span style="display:inline-flex;align-items:center;padding:0.25rem 0.6rem;background:{{ $ss['bg'] }};border-radius:9999px;font-size:0.65rem;font-weight:700;color:{{ $ss['color'] }};font-family:'Syne',sans-serif;white-space:nowrap;">
                                {{ $ss['label'] }}
                            </span>
                        </td>
                        <td style="padding:0.85rem 1rem;text-align:right;">
                            @if($ride->final_fare)
                            <span style="font-size:0.78rem;font-weight:700;color:#4ade80;font-family:'Syne',sans-serif;">R {{ number_format((float)$ride->final_fare, 2) }}</span>
                            @elseif($ride->estimated_fare)
                            <span style="font-size:0.75rem;color:#71717a;">~R {{ number_format((float)$ride->estimated_fare, 2) }}</span>
                            @else
                            <span style="font-size:0.72rem;color:#71717a;">—</span>
                            @endif
                        </td>
                        <td style="padding:0.85rem 1.5rem;text-align:right;white-space:nowrap;">
                            <span style="font-size:0.7rem;color:#71717a;">{{ $ride->created_at->diffForHumans() }}</span>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>

</div>

</x-app-layout>
