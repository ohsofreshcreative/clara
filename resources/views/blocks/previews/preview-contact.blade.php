<!--- contact preview --->

<div class="acf-preview" @if(!empty($nomt)) data-acf-nomt @endif>
	<div class="acf-preview__meta">
		<div class="acf-preview__heading">
			<div class="acf-preview__title">Kontakt</div>
			<span class="acf-preview__slug">acf/contact</span>
		</div>
		@include('partials.block-preview-settings')
	</div>
	<div class="acf-preview__content">
		@include('partials.block-preview-intro', ['preview' => [
			'header' => $g_contact['header'] ?? null,
			'subheader' => $g_contact['subheader'] ?? null,
			'lines' => [$g_contact['address'] ?? null, $g_contact['phone'] ?? null, $g_contact['phone2'] ?? null, $g_contact['mail'] ?? null, $g_contact['hours'] ?? null],
			'buttons' => [$g_contact['button'] ?? null],
			'image' => $g_contact['image'] ?? null,
			'flip' => $flip ?? false,
		]])
		@include('partials.block-preview-items', [
			'previewItems' => $departments ?? [],
			'previewLabel' => 'Działy',
			'previewTextKey' => 'phone',
		])
	</div>
</div>
