<!--- video preview --->

<div class="acf-preview" @if(!empty($nomt)) data-acf-nomt @endif>
	<div class="acf-preview__meta">
		<div class="acf-preview__heading">
			<div class="acf-preview__title">Wideo</div>
			<span class="acf-preview__slug">acf/video</span>
		</div>
		@include('partials.block-preview-settings')
	</div>
	<div class="acf-preview__content">
		@include('partials.block-preview-intro', ['preview' => [
			'header' => $g_video['header'] ?? null,
			'subheader' => $g_video['video']['filename'] ?? null,
			'flip' => $flip ?? false,
		]])
	</div>
</div>
