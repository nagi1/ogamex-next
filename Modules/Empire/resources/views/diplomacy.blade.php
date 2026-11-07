{{-- Empire module: the alliance's diplomacy board. --}}
<div class="allianceContent empireDiplomacy">
    <div class="sectioncontent" style="display:block;">
        <div class="contentz">
            <h3>{{ __('t_empire.relations') }}</h3>
            <table class="members zebra bborder">
                <thead>
                    <tr>
                        <th>{{ __('t_empire.col_alliance') }}</th>
                        <th>{{ __('t_empire.col_stance') }}</th>
                        <th>{{ __('t_empire.col_since') }}</th>
                        <th>{{ __('t_empire.col_reputation') }}</th>
                        @if($canAct)<th></th>@endif
                    </tr>
                </thead>
                <tbody>
                    @forelse($rows as $row)
                        <tr>
                            <td>[{{ $row['tag'] }}]</td>
                            <td>
                                @if($row['stance'] === \Modules\Empire\Enums\TreatyStance::War)
                                    {{ __('t_empire.stance_war') }}
                                    @if($row['peace_from_them']) ({{ __('t_empire.peace_offered_to_us') }}) @elseif($row['peace_from_us']) ({{ __('t_empire.peace_offered_by_us') }}) @endif
                                @elseif($row['pending'])
                                    {{ $row['incoming'] ? __('t_empire.pact_offered_to_us') : __('t_empire.pact_offered_by_us') }}
                                @else
                                    {{ __('t_empire.stance_pact') }}
                                    @if($row['notice']) ({{ __('t_empire.notice_until', ['time' => $row['notice']->format('Y-m-d H:i')]) }}) @endif
                                @endif
                            </td>
                            <td>{{ $row['since']->format('Y-m-d H:i') }}</td>
                            <td>{{ __('t_empire.broken_count', ['count' => (int) ($broken[$row['other']] ?? 0)]) }}</td>
                            @if($canAct)
                                <td>
                                    @php($acts = $row['stance'] === \Modules\Empire\Enums\TreatyStance::War
                                        ? ($row['peace_from_them'] ? ['accept-peace'] : ['offer-peace'])
                                        : ($row['pending'] ? ($row['incoming'] ? ['accept-pact', 'decline-pact'] : []) : ($row['notice'] ? ['break-pact'] : ['cancel-pact', 'break-pact'])))
                                    @foreach($acts as $act)
                                        <form method="post" action="{{ route('empire.diplomacy.act', ['act' => $act]) }}" style="display:inline">
                                            @csrf
                                            <input type="hidden" name="other_alliance_id" value="{{ $row['other'] }}">
                                            <button type="submit" class="btn_blue">{{ __('t_empire.act.' . $act, ['hours' => \Modules\Empire\Services\DiplomacyService::NOTICE_HOURS]) }}</button>
                                        </form>
                                    @endforeach
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="{{ $canAct ? 5 : 4 }}">{{ __('t_empire.no_relations') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
            <p>{{ __('t_empire.own_reputation', ['count' => $ownBroken]) }}</p>

            @if($canAct && $others->isNotEmpty())
                <h3>{{ __('t_empire.new_act') }}</h3>
                @foreach(['war', 'offer-pact'] as $act)
                    <form method="post" action="{{ route('empire.diplomacy.act', ['act' => $act]) }}">
                        @csrf
                        <select name="other_alliance_id">
                            @foreach($others as $other)
                                <option value="{{ $other->id }}">[{{ $other->alliance_tag }}] {{ $other->alliance_name }} — {{ __('t_empire.broken_count', ['count' => (int) ($broken[$other->id] ?? 0)]) }}</option>
                            @endforeach
                        </select>
                        <button type="submit" class="btn_blue">{{ __('t_empire.act.' . $act, ['hours' => \Modules\Empire\Services\DiplomacyService::NOTICE_HOURS]) }}</button>
                    </form>
                @endforeach
            @endif
        </div>
    </div>
</div>
