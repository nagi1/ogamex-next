<li>
    <a class="empireDiplomacy navi" rel="{{ route('empire.diplomacy.tab') }}" data-tab="empireDiplomacy">
        <span>{{ __('t_empire.tab') }}</span>
    </a>
    {{-- The host's alliance script binds only its own tabs, so this one loads itself into the same content area. --}}
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var bar = $('#alliancecomponent #tab-ally');
            bar.on('click', '.navi', function () {
                if (!$(this).hasClass('empireDiplomacy')) {
                    bar.find('.empireDiplomacy').parent().removeClass('aktiv');
                }
            });
            bar.on('click', '.empireDiplomacy', function (event) {
                event.preventDefault();
                var link = $(this);
                bar.children('li').removeClass('aktiv');
                link.parent().addClass('aktiv');
                $.getJSON(link.attr('rel'))
                    .done(function (data) { $('#alliancecomponent .allianceContent').html(data.content['empire/diplomacy']); })
                    .fail(function (xhr) { fadeBox((xhr.responseJSON && xhr.responseJSON.message) || @json(__('t_empire.tab_failed')), true); });
            });
        });
    </script>
</li>
