<!--- proces preview --->

<div class="acf-preview" @if(!empty($nomt)) data-acf-nomt @endif>
	<div class="acf-preview__meta">
		<div class="acf-preview__heading">
			<div class="acf-preview__title">Proces</div>
			<span class="acf-preview__slug">acf/proces</span>
		</div>
		@include('partials.block-preview-settings')
	</div>
	<div class="acf-preview__content">
		@include('partials.block-preview-intro', ['preview' => [
			'header' => $g_proces['header'] ?? null,
			'html' => $g_proces['txt'] ?? null,
			'flip' => $flip ?? false,
		]])
		@include('partials.block-preview-items', [
			'previewItems' => $r_proces ?? [],
			'previewLabel' => 'Kroki',
		])
	</div>
</div>
