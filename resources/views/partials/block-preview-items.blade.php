@php
$items = array_values(array_filter((array) ($previewItems ?? []), 'is_array'));
$limit = $previewLimit ?? 6;
$titleKeys = isset($previewTitleKey) ? [$previewTitleKey] : ['title', 'header', 'card_title', 'name', 'tab', 'group_title', 'label', 'number', 'col1'];
$textKeys = isset($previewTextKey) ? [$previewTextKey] : ['text', 'txt', 'opis', 'excerpt', 'quote', 'value'];
$firstFilled = function (array $item, array $keys) {
	foreach ($keys as $key) {
		if (filled($item[$key] ?? null) && is_string($item[$key])) {
			return $item[$key];
		}
	}

	return null;
};
@endphp

@if (!empty($items))
<div class="acf-preview__items">
	<p class="acf-preview__items-label">
		{{ $previewLabel ?? 'Elementy' }}
		<span class="acf-preview__count">{{ count($items) }}</span>
	</p>
	<div @class(['acf-preview__grid', 'acf-preview__grid--icons' => !empty($previewIcons)])>
		@foreach (array_slice($items, 0, $limit) as $item)
		@php
		$itemTitle = $firstFilled($item, $titleKeys) ?? ($item['link']['title'] ?? null);
		$itemText = $firstFilled($item, $textKeys);
		$itemImage = $item['image'] ?? $item['icon'] ?? (isset($item['ID'], $item['url']) ? $item : null);
		$itemImageUrl = $item['image_url'] ?? $item['icon_url'] ?? null;
		@endphp
		<div class="acf-preview__card">
			@if (!empty($itemImage['ID']))
			<figure class="acf-preview__thumb">{!! wp_get_attachment_image($itemImage['ID'], 'thumbnail') !!}</figure>
			@elseif (!empty($itemImageUrl))
			<figure class="acf-preview__thumb"><img src="{{ $itemImageUrl }}" alt=""></figure>
			@endif
			<div class="acf-preview__card-body">
				@if (filled($itemTitle))
				<p class="acf-preview__card-title">{{ $itemTitle }}</p>
				@endif
				@if (filled($itemText))
				<p class="acf-preview__card-text">{{ wp_strip_all_tags($itemText) }}</p>
				@endif
			</div>
		</div>
		@endforeach
		@if (count($items) > $limit)
		<div class="acf-preview__more">+ {{ count($items) - $limit }}</div>
		@endif
	</div>
</div>
@endif
