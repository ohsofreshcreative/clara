<!--- banner --->

@if (!empty($block->preview))
@include('blocks.previews.preview-banner')
@else

<section
	data-gsap-anim="section"
	@if(!empty($section_id)) id="{{ $section_id }}" @endif
	@class([ 'b-banner relative -spt overflow-visible' ,
	$sectionClass=> filled($sectionClass),
	$section_class => filled($section_class),
	$background => filled($background) && $background !== 'none',
	])>

	<div class="__wrapper c-main relative z-10 -spt">
		<div class="__content flex flex-col gap-6 max-w-3xl">
			<h1 data-gsap-element="header" class="text-h2">
				{{ $g_banner['title'] }}
			</h1>
			@if (!empty($g_banner['text']))
			<div data-gsap-element="text" class="__txt">
				{!! $g_banner['text'] !!}
			</div>
			@endif
		</div>
	</div>
</section>

@endif
