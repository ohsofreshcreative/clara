<!--- overlap preview --->

<div class="acf-preview" @if(!empty($nomt)) data-acf-nomt @endif>
	<div class="acf-preview__meta">
		<div class="acf-preview__heading">
			<div class="acf-preview__title">Treść oraz duże kafelki</div>
			<span class="acf-preview__slug">acf/overlap</span>
		</div>
		@include('partials.block-preview-settings')
	</div>
	<div class="acf-preview__content">
		@include('partials.block-preview-intro', ['preview' => [
			'header' => $g_overlap['header'] ?? null,
			'html' => $g_overlap['text'] ?? null,
			'flip' => $flip ?? false,
		]])
		@include('partials.block-preview-items', [
			'previewItems' => $r_overlap ?? [],
			'previewLabel' => 'Kafelki',
		])
	</div>
</div>
