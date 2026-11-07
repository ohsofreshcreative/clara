<!--- tabs preview --->

<div class="acf-preview" @if(!empty($nomt)) data-acf-nomt @endif>
	<div class="acf-preview__meta">
		<div class="acf-preview__heading">
			<div class="acf-preview__title">Zakładki</div>
			<span class="acf-preview__slug">acf/tabs</span>
		</div>
		@include('partials.block-preview-settings')
	</div>
	<div class="acf-preview__content">
		@include('partials.block-preview-intro', ['preview' => [
			'header' => $g_tabs['header'] ?? null,
			'text' => $g_tabs['text'] ?? null,
			'buttons' => [$g_tabs['button'] ?? null],
			'flip' => $flip ?? false,
		]])
		@include('partials.block-preview-items', [
			'previewItems' => $r_tabs ?? [],
			'previewLabel' => 'Zakładki',
		])
	</div>
</div>
