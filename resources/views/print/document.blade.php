<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8"/>
<title>{{ $o->number }}</title>
@php($L = new \App\Support\Print\Layout($o, $p, $o->form()))
@php($blocks = $L->blocks())
@php($land = $p->orientation === 'landscape')
<style>
{!! $fontCss !!}
html, body { margin: 0; padding: 0; }
body { font-family: 'Carlito', sans-serif; color: #111; font-size: 9.5pt; line-height: 1.22; -webkit-print-color-adjust: exact; }
table { border-collapse: collapse; width: 100%; }
td, th { vertical-align: middle; }
.band td { border: 0; padding: 0; vertical-align: top; }
.band .left { font-size: 8pt; color: #333; white-space: pre-line; width: 34%; }
.band .center { text-align: center; font-weight: bold; font-size: 11pt; white-space: pre-line; }
.band .right { text-align: right; width: 22%; }
.band img { max-height: 15mm; max-width: 40mm; }
.titlebar { margin: 4mm 0 3mm; }
.titlebar td { border: 1px solid #111; padding: 2.2mm 3mm; }
.titlebar .t { font-size: 15pt; font-weight: bold; text-align: center; letter-spacing: .2pt; background: #E9E3F1; }
.titlebar .k { font-size: 7.5pt; color: #444; text-align: center; display: block; text-transform: none; }
.titlebar .n { font-family: 'Zilla Slab', serif; font-weight: 700; font-size: 17pt; text-align: center; display: block; letter-spacing: .5pt; }
.titlebar .d { font-size: 10.5pt; text-align: center; display: block; font-weight: bold; }
.blk { margin-top: 3mm; page-break-inside: auto; }
.blk-title { background: #D9D9D9; border: 1px solid #111; border-bottom: 0; font-weight: bold; padding: 1.2mm 2mm; font-size: 9.5pt; }
.grid { table-layout: fixed; }
.grid td { border: 1px solid #111; padding: 1.3mm 1.8mm; word-wrap: break-word; }
.grid .lbl { background: #F2F2F2; font-weight: bold; font-size: 8.5pt; }
.grid .val { font-size: 9.5pt; }
.grid .num { text-align: right; }
.data { table-layout: fixed; }
.data th { background: #D9D9D9; border: 1px solid #111; font-size: 8pt; font-weight: bold; padding: 1.2mm 1mm; text-align: center; }
.data td { border: 1px solid #111; padding: 1mm 1.4mm; font-size: {{ $land ? '8.5pt' : '8.5pt' }}; word-wrap: break-word; }
.data td.num { text-align: right; white-space: nowrap; }
.data td.sn { text-align: center; color: #333; }
.data tr { page-break-inside: avoid; }
.data tfoot td { font-weight: bold; background: #F2F2F2; }
.totals { width: auto; margin-left: auto; margin-top: 2mm; }
.totals td { border: 1px solid #111; padding: 1.3mm 2.5mm; }
.totals .lbl { background: #F2F2F2; font-weight: bold; font-size: 8.5pt; }
.totals .val { font-weight: bold; text-align: right; min-width: 32mm; font-size: 10pt; }
.note { border: 1px solid #111; padding: 2mm 2.5mm; white-space: pre-line; }
.note.heading { border: 0; padding: 1mm 0; font-weight: bold; font-size: 11pt; }
.sig td { border: 1px solid #111; padding: 1.3mm 2mm; height: 14mm; }
.sig th { background: #D9D9D9; border: 1px solid #111; font-size: 8pt; padding: 1.2mm; }
.sig .role { font-weight: bold; font-size: 9pt; }
.sig .pl { display: block; font-weight: normal; font-size: 7.5pt; color: #444; margin-top: .6mm; }
.sig img { max-height: 13mm; max-width: 44mm; }
.sig .c { text-align: center; }
.empty-row td { color: #888; text-align: center; }
</style>
</head>
<body>
@if($p->show_header)
<table class="band"><tr>
  <td class="left">{{ $p->headerLeftText() }}</td>
  <td class="center">{{ $p->headerCenterText() }}</td>
  <td class="right">@if($p->show_logo)<img src="{{ $p->logoSrc() }}" alt="">@endif</td>
</tr></table>
@endif

<table class="titlebar"><tr>
  <td class="t" style="width:58%">{{ $p->title ?: $p->name }}</td>
  <td style="width:26%"><span class="k">{{ $L->label('_number', 'Transaction Reference Number') }}</span><span class="n">{{ $o->number ?: '—' }}</span></td>
  <td style="width:16%"><span class="k">{{ $L->label('_date', 'Date') }}</span><span class="d">{{ $o->date ? $o->date->format('d/m/Y') : '' }}</span></td>
</tr></table>

@foreach($blocks as $b)
  @if($b['type'] === 'section')
    <div class="blk">
      @if($b['title'])<div class="blk-title">{{ $b['title'] }}</div>@endif
      @foreach($b['rows'] as $row)
        <table class="grid"><tr>
          @foreach($row as $c)
            @php($lw = $c['width'] >= 12 ? 18 : ($c['width'] >= 6 ? 15 : $c['width'] * 3.4))
            <td class="lbl" style="width: {{ round($lw * 100 / 100, 2) }}%">{{ $c['label'] }}</td>
            <td class="val {{ $c['num'] ? 'num' : '' }}" style="width: {{ round($c['width'] * 100 / 12 - $lw, 2) }}%">{{ $c['value'] }}</td>
          @endforeach
        </tr></table>
      @endforeach
    </div>
  @elseif($b['type'] === 'totals')
    <table class="totals">
      @foreach($b['rows'] as $row)
        @foreach($row as $c)
          <tr><td class="lbl">{{ $c['label'] }}</td><td class="val">{{ $c['value'] }}</td></tr>
        @endforeach
      @endforeach
    </table>
  @elseif($b['type'] === 'table')
    @php($tw = 34 + array_sum(array_column($b['cols'], 'w')))
    <div class="blk">
      @if($b['title'])<div class="blk-title">{{ $b['title'] }}</div>@endif
      <table class="data">
        <thead><tr><th style="width: {{ round(34 * 100 / $tw, 2) }}%">SN</th>
          @foreach($b['cols'] as $c)<th style="width: {{ round($c['w'] * 100 / $tw, 2) }}%">{{ $c['label'] }}</th>@endforeach</tr></thead>
        <tbody>
          @forelse($b['rows'] as $i => $r)
            <tr><td class="sn">{{ $i + 1 }}</td>@foreach($r as $ci => $v)<td class="{{ $b['cols'][$ci]['num'] ? 'num' : '' }}">{{ $v }}</td>@endforeach</tr>
          @empty
            <tr class="empty-row"><td colspan="{{ count($b['cols']) + 1 }}">—</td></tr>
          @endforelse
        </tbody>
        @if(collect($b['cols'])->contains(fn ($c) => $c['sum'] !== null))
          <tfoot><tr><td class="sn">Σ</td>@foreach($b['cols'] as $c)<td class="num">{{ $c['sum'] }}</td>@endforeach</tr></tfoot>
        @endif
      </table>
    </div>
  @elseif($b['type'] === 'text')
    <div class="blk">
      @if($b['title'] && $b['style'] !== 'heading')<div class="blk-title">{{ $b['title'] }}</div>@endif
      <div class="note {{ $b['style'] }}">{{ $b['text'] }}</div>
    </div>
  @elseif($b['type'] === 'signatures')
    <div class="blk" style="page-break-inside: avoid">
      <div class="blk-title">{{ $b['title'] ?: 'Approvals' }}</div>
      <table class="sig">
        <thead><tr><th style="width:32%">Step</th><th style="width:30%">Name &amp; Title</th><th style="width:24%">Signature</th><th style="width:14%">Date</th></tr></thead>
        <tbody>
        @foreach($o->signatures as $s)
          @if($s->individual_id || $s->required)
          <tr>
            <td><span class="role">{{ $s->role }}</span>@if($s->print_label)<span class="pl">{{ $s->print_label }}</span>@endif</td>
            <td>@if($s->individual)<b>{{ $s->individual->name }}</b><br><span style="font-size:8pt">{{ $s->individual->title }}</span>@endif</td>
            <td class="c">@if($s->signature)<img src="{{ $s->signature }}" alt="">@endif</td>
            <td class="c">{{ $s->date?->format('d/m/Y') }}</td>
          </tr>
          @endif
        @endforeach
        </tbody>
      </table>
      @if($o->form()->hasPayment && ($o->paid_ref || $o->state === 'done'))
        <table class="grid" style="margin-top:2mm"><tr>
          <td class="lbl" style="width:14%">Payment T Ref</td><td class="val" style="width:16%"><b>{{ $o->paid_ref }}</b></td>
          <td class="lbl" style="width:14%">Paid Amount</td><td class="val num" style="width:18%">{{ $o->paid_amount !== null ? \App\Support\Print\Layout::num($o->paid_amount, 2) : '' }}</td>
          <td class="lbl" style="width:10%">Rate</td><td class="val num" style="width:8%">{{ $o->paid_rate }}</td>
          <td class="lbl" style="width:8%">Date</td><td class="val" style="width:12%">{{ $o->paid_date?->format('d/m/Y') }}</td>
        </tr></table>
      @endif
    </div>
  @endif
@endforeach
</body>
</html>
