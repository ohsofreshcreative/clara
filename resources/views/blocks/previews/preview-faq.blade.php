<!--- faq preview --->

<div class="acf-preview" @if(!empty($nomt)) data-acf-nomt @endif>
	<div class="acf-preview__meta">
		<div class="acf-preview__heading">
			<div class="acf-preview__title">Najczęściej zadawane pytania</div>
			<span class="acf-preview__slug">acf/faq</span>
		</div>
		@include('partials.block-preview-settings')
	</div>
	<div class="acf-preview__content">
		@include('partials.block-preview-intro', ['preview' => [
			'header' => $g_faq['header'] ?? null,
			'html' => $g_faq['text'] ?? null,
			'buttons' => [$g_faq['button'] ?? null],
			'image' => $g_faq['image'] ?? null,
			'flip' => $flip ?? false,
		]])
		@include('partials.block-preview-items', [
			'previewItems' => $r_faq ?? [],
			'previewLabel' => 'Pytania',
		])
	</div>
</div>
