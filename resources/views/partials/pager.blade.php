@if ($paginator->hasPages())
  <span>{{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} of {{ $paginator->total() }}</span>
  @if($paginator->onFirstPage())<span class="btn btn-sm btn-icon" aria-disabled="true" style="opacity:.4"><i class="fa-solid fa-chevron-left"></i></span>
  @else<a class="btn btn-sm btn-icon" href="{{ $paginator->previousPageUrl() }}" aria-label="Previous"><i class="fa-solid fa-chevron-left"></i></a>@endif
  @if($paginator->hasMorePages())<a class="btn btn-sm btn-icon" href="{{ $paginator->nextPageUrl() }}" aria-label="Next"><i class="fa-solid fa-chevron-right"></i></a>
  @else<span class="btn btn-sm btn-icon" style="opacity:.4"><i class="fa-solid fa-chevron-right"></i></span>@endif
@endif
