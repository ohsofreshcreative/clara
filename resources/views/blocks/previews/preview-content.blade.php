<!--- content preview --->

<div class="acf-preview" @if(!empty($nomt)) data-acf-nomt @endif>
	<div class="acf-preview__meta">
		<div class="acf-preview__heading">
			<div class="acf-preview__title">Tekst oraz zdjęcie</div>
			<span class="acf-preview__slug">acf/content</span>
		</div>
		@include('partials.block-preview-settings')
	</div>
	<div class="acf-preview__content">
		@include('partials.block-preview-intro', ['preview' => [
			'header' => $g_content['header'] ?? null,
			'html' => $g_content['text'] ?? null,
			'buttons' => [$g_content['button1'] ?? null, $g_content['button2'] ?? null],
			'image' => $g_content['image'] ?? null,
			'flip' => $flip ?? false,
		]])
	</div>
</div>
