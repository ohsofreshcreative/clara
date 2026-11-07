<!--- accordion preview --->

<div class="acf-preview" @if(!empty($nomt)) data-acf-nomt @endif>
	<div class="acf-preview__meta">
		<div class="acf-preview__heading">
			<div class="acf-preview__title">Rozwijane panele</div>
			<span class="acf-preview__slug">acf/accordion</span>
		</div>
		@include('partials.block-preview-settings')
	</div>
	<div class="acf-preview__content">
		@include('partials.block-preview-intro', ['preview' => [
			'header' => $g_accordion['title'] ?? null,
			'html' => $g_accordion['text'] ?? null,
			'image' => $g_accordion['image'] ?? null,
			'flip' => $flip ?? false,
		]])
		@include('partials.block-preview-items', [
			'previewItems' => $r_accordion ?? [],
			'previewLabel' => 'Panele',
		])
	</div>
</div>
