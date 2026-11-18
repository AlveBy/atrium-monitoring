{{--
    This file is part of Atrium (https://github.com/AlveBy/atrium).
    Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
--}}
{{-- The Web Vitals script, for a theme of your own: <x-atrium-monitoring::rum /> in the <head>. --}}
{!! app(\Atrium\Monitoring\Rum\RumScript::class)->tag(request()) !!}
