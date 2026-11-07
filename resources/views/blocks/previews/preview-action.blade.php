<!--- action preview --->

<div class="acf-preview" @if(!empty($nomt)) data-acf-nomt @endif>
	<div class="acf-preview__meta">
		<div class="acf-preview__heading">
			<div class="acf-preview__title">CTA - Wpis</div>
			<span class="acf-preview__slug">acf/action</span>
		</div>
		@include('partials.block-preview-settings')
	</div>
	<div class="acf-preview__content">
		@include('partials.block-preview-intro', ['preview' => [
			'header' => $g_action['header'] ?? null,
			'html' => $g_action['text'] ?? null,
			'buttons' => [$g_action['button1'] ?? null],
			'image' => $g_action['image'] ?? null,
			'flip' => $flip ?? false,
		]])
	</div>
</div>
