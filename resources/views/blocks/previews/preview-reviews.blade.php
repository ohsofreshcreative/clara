<!--- reviews preview --->

<div class="acf-preview" @if(!empty($nomt)) data-acf-nomt @endif>
	<div class="acf-preview__meta">
		<div class="acf-preview__heading">
			<div class="acf-preview__title">Slider - Opinie</div>
			<span class="acf-preview__slug">acf/reviews</span>
		</div>
		@include('partials.block-preview-settings')
	</div>
	<div class="acf-preview__content">
		@include('partials.block-preview-intro', ['preview' => [
			'header' => $header ?? null,
			'flip' => $flip ?? false,
		]])
		@include('partials.block-preview-items', [
			'previewItems' => $r_reviews ?? [],
			'previewLabel' => 'Opinie',
		])
	</div>
</div>
