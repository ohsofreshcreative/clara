<!--- logos preview --->

<div class="acf-preview" @if(!empty($nomt)) data-acf-nomt @endif>
	<div class="acf-preview__meta">
		<div class="acf-preview__heading">
			<div class="acf-preview__title">Logotypy partnerów</div>
			<span class="acf-preview__slug">acf/logos</span>
		</div>
		@include('partials.block-preview-settings')
	</div>
	<div class="acf-preview__content">
		@include('partials.block-preview-intro', ['preview' => [
			'header' => $g_logos['header'] ?? null,
			'flip' => $flip ?? false,
		]])
		@include('partials.block-preview-items', [
			'previewItems' => $g_logos['gallery'] ?? [],
			'previewLabel' => 'Logotypy',
			'previewLimit' => 8,
		])
	</div>
</div>
