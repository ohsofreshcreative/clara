<!--- gallery preview --->

<div class="acf-preview" @if(!empty($nomt)) data-acf-nomt @endif>
	<div class="acf-preview__meta">
		<div class="acf-preview__heading">
			<div class="acf-preview__title">Galeria</div>
			<span class="acf-preview__slug">acf/gallery</span>
		</div>
		@include('partials.block-preview-settings')
	</div>
	<div class="acf-preview__content">
		@include('partials.block-preview-intro', ['preview' => [
			'header' => $g_gallery['header'] ?? null,
			'flip' => $flip ?? false,
		]])
		@include('partials.block-preview-items', [
			'previewItems' => $g_gallery['gallery'] ?? [],
			'previewLabel' => 'Zdjęcia',
			'previewLimit' => 8,
		])
	</div>
</div>
