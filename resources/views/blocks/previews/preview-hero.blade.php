<!--- hero preview --->

<div class="acf-preview" @if(!empty($nomt)) data-acf-nomt @endif>
	<div class="acf-preview__meta">
		<div class="acf-preview__heading">
			<div class="acf-preview__title">Hero</div>
			<span class="acf-preview__slug">acf/hero</span>
		</div>
		@include('partials.block-preview-settings')
	</div>
	<div class="acf-preview__content">
		@include('partials.block-preview-intro', ['preview' => [
			'header' => $g_hero['title'] ?? null,
			'html' => $g_hero['text'] ?? null,
			'buttons' => [$g_hero['button1'] ?? null, $g_hero['button2'] ?? null],
			'image' => $g_hero['image'] ?? null,
			'flip' => $flip ?? false,
		]])
	</div>
</div>
