@php
$p = $preview ?? [];
$p_buttons = array_values(array_filter($p['buttons'] ?? [], fn ($button) => !empty($button['title'])));
$p_lines = array_values(array_filter($p['lines'] ?? [], 'filled'));
$p_image = !empty($p['image']['ID']) ? $p['image']['ID'] : null;
$p_has_body = filled($p['eyebrow'] ?? null) || filled($p['header'] ?? null) || filled($p['subheader'] ?? null)
	|| filled($p['text'] ?? null) || filled($p['html'] ?? null) || !empty($p_lines) || !empty($p_buttons);
@endphp

@if ($p_has_body || $p_image)
<div @class([
	'acf-preview__intro',
	'acf-preview__intro--media' => $p_image,
	'acf-preview__intro--flip' => $p_image && !empty($p['flip']),
	])>
	<div class="acf-preview__body">
		@if (filled($p['eyebrow'] ?? null))
		<p class="acf-preview__eyebrow">{{ $p['eyebrow'] }}</p>
		@endif
		@if (filled($p['header'] ?? null))
		<p class="acf-preview__header">{{ $p['header'] }}</p>
		@endif
		@if (filled($p['subheader'] ?? null))
		<p class="acf-preview__subheader">{{ $p['subheader'] }}</p>
		@endif
		@if (filled($p['text'] ?? null))
		<p class="acf-preview__text">{{ wp_strip_all_tags($p['text']) }}</p>
		@endif
		@if (filled($p['html'] ?? null))
		<div class="acf-preview__text acf-preview__text--rich">{!! $p['html'] !!}</div>
		@endif
		@if (!empty($p_lines))
		<ul class="acf-preview__lines">
			@foreach ($p_lines as $line)
			<li>{!! nl2br(e(wp_strip_all_tags($line))) !!}</li>
			@endforeach
		</ul>
		@endif
		@if (!empty($p_buttons))
		<div class="acf-preview-actions">
			@foreach ($p_buttons as $button)
			<span @class(['acf-preview-button', 'acf-preview-button--ghost' => !$loop->first])>{{ $button['title'] }}</span>
			@endforeach
		</div>
		@endif
	</div>
	@if ($p_image)
	<figure class="acf-preview__media">{!! wp_get_attachment_image($p_image, 'medium') !!}</figure>
	@endif
</div>
@endif
