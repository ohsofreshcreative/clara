<!--- slider preview --->

<div class="acf-preview" @if(!empty($nomt)) data-acf-nomt @endif>
	<div class="acf-preview__meta">
		<div class="acf-preview__heading">
			<div class="acf-preview__title">Slider - Oferta</div>
			<span class="acf-preview__slug">acf/slider</span>
		</div>
		@include('partials.block-preview-settings')
	</div>
	<div class="acf-preview__content">
		@include('partials.block-preview-intro', ['preview' => [
			'header' => $slider_title ?? null,
			'flip' => $flip ?? false,
		]])
		@include('partials.block-preview-items', [
			'previewItems' => $slides ?? [],
			'previewLabel' => 'Slajdy',
		])
	</div>
</div>
