<!--- wysiwyg preview --->

<div class="acf-preview" @if(!empty($nomt)) data-acf-nomt @endif>
	<div class="acf-preview__meta">
		<div class="acf-preview__heading">
			<div class="acf-preview__title">Edytor treści</div>
			<span class="acf-preview__slug">acf/wysiwyg</span>
		</div>
		@include('partials.block-preview-settings')
	</div>
	<div class="acf-preview__content">
		@include('partials.block-preview-intro', ['preview' => [
			'header' => $g_wysiwyg['header'] ?? null,
			'html' => $g_wysiwyg['txt'] ?? null,
			'buttons' => [$g_wysiwyg['button'] ?? null],
			'flip' => $flip ?? false,
		]])
	</div>
</div>
