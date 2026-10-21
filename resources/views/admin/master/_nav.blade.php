<nav class="subnav">
  @foreach($nav->groupBy('group') as $g => $items)
    <div class="subnav-group">{{ $g }}</div>
    @foreach($items as $n)
      <a href="{{ route('admin.master.index', $n['key']) }}" class="{{ $n['key'] === $c['key'] ? 'on' : '' }}"><i class="fa-solid {{ $n['icon'] }}"></i><span>{{ $n['title'] }}</span><small>{{ number_format($n['count']) }}</small></a>
    @endforeach
  @endforeach
</nav>
