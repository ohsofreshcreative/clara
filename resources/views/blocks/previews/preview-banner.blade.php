<!--- banner preview --->

<div class="acf-preview" @if(!empty($nomt)) data-acf-nomt @endif>
	<div class="acf-preview__meta">
		<div class="acf-preview__heading">
			<div class="acf-preview__title">Hero - Podstrona</div>
			<span class="acf-preview__slug">acf/banner</span>
		</div>
		@include('partials.block-preview-settings')
	</div>
	<div class="acf-preview__content">
		@include('partials.block-preview-intro', ['preview' => [
			'header' => $g_banner['title'] ?? null,
			'html' => $g_banner['text'] ?? null,
			'flip' => $flip ?? false,
		]])
	</div>
</div>
