@php
$preview_background = !empty($background) && $background !== 'none' ? $background : null;
$preview_settings = array_filter([
	'ID' => $section_id ?? null,
	'Klasa' => $section_class ?? null,
	'Odwrócenie' => !empty($flip) ? 'tak' : null,
	'Szeroki' => !empty($wide) ? 'tak' : null,
	'Większy odstęp' => !empty($gap) ? 'tak' : null,
	'Bez listy' => !empty($nolist) ? 'tak' : null,
]);
@endphp

@if ($preview_background || !empty($nomt) || !empty($preview_settings))
<div class="acf-preview__settings">
	@if ($preview_background)
	<span class="acf-preview__setting acf-preview__setting--background">
		<span class="acf-preview__swatch" data-bg="{{ $preview_background }}"></span>
		Tło: {{ \App\Support\SectionBackgrounds::choices()[$preview_background] ?? $preview_background }}
	</span>
	@endif
	@if (!empty($nomt))
	<span class="acf-preview__setting acf-preview__setting--nomt">Bez marginesu górnego</span>
	@endif
	@foreach ($preview_settings as $label => $value)
	<span class="acf-preview__setting">{{ $label }}: {{ $value }}</span>
	@endforeach
</div>
@endif
