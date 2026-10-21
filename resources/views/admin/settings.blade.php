@extends('layouts.app', ['title' => 'Settings'])
@section('crumbs')<span>Administration</span><i class="fa-solid fa-chevron-right"></i><b>Settings</b>@endsection
@section('content')
@php($logo = \App\Models\Setting::get('org_logo') ?: \App\Support\Print\Assets::logoDataUri())
<form method="post" action="{{ route('admin.settings.update') }}" enctype="multipart/form-data" x-data="{ logo: null }">
  @csrf @method('PUT')
  <div class="page-head">
    <div class="grow"><h1>Settings</h1><p>Organization details used on every printout, attachment limits, the uploader email and the yearly transaction counters.</p></div>
    <button class="btn btn-primary"><i class="fa-solid fa-check"></i> Save settings</button>
  </div>
  <div class="grid grid-2">
    <div class="panel">
      <div class="panel-head"><h2>Organization</h2></div>
      <div class="panel-body fgrid">
        <div class="field" style="grid-column:span 8">
          <label for="s_org">Name on English printouts <code>{org}</code></label><input id="s_org" class="input" name="org_name" value="{{ old('org_name', \App\Models\Setting::get('org_name')) }}" required>
          <label for="s_org_tr" style="margin-top:10px">Name on Turkish printouts <code>{org_tr}</code></label><input id="s_org_tr" class="input" name="org_name_tr" value="{{ old('org_name_tr', \App\Models\Setting::get('org_name_tr')) }}">
        </div>
        <div class="field" style="grid-column:span 4"><label>Logo</label>
          <label class="logo-pick"><input type="file" name="logo_file" accept="image/png,image/jpeg" class="sr-only" @change="logo = $event.target.files[0] ? URL.createObjectURL($event.target.files[0]) : null">
            <img :src="logo || @js($logo)" alt="Organization logo"><span><i class="fa-solid fa-arrow-up-from-bracket"></i> Replace</span></label>
          @if(\App\Models\Setting::get('org_logo'))<label class="check hint"><input type="checkbox" name="logo_reset" value="1"> Back to the original logo</label>@endif
          <span class="hint">A printout can still use its own logo (Forms management).</span>
        </div>
      </div>
    </div>
    <div class="panel">
      <div class="panel-head"><h2>Security, attachments &amp; uploader</h2></div>
      <div class="panel-body fgrid">
        <div class="field" style="grid-column:span 5"><label for="s_mb">Largest attachment</label>
          <div class="input-unit"><input id="s_mb" type="number" min="1" max="100" class="input num" name="max_attachment_mb" value="{{ old('max_attachment_mb', \App\Models\Setting::get('max_attachment_mb')) }}"><span>MB</span></div>
          <span class="hint">PDF, PNG and JPG are accepted.</span></div>
        <div class="field" style="grid-column:span 7"><label for="s_idle">Sign out after inactivity</label>
          <div class="input-unit"><input id="s_idle" type="number" min="5" max="720" class="input num" name="idle_minutes" value="{{ old('idle_minutes', \App\Models\Setting::get('idle_minutes')) }}"><span>minutes</span></div>
          <span class="hint">Closing the browser does not keep anyone signed in past this time.</span></div>
        <div class="field" style="grid-column:span 5"><label for="s_due">Advance "due soon" warning</label>
          <div class="input-unit"><input id="s_due" type="number" min="1" max="60" class="input num" name="due_soon_days" value="{{ old('due_soon_days', \App\Models\Setting::get('due_soon_days')) }}"><span>days</span></div></div>
        <div class="field" style="grid-column:span 7"><label>Payments</label>
          <label class="check" style="height:38px"><span class="switch"><input type="checkbox" name="pay_through_remittance" value="1" @checked(\App\Models\Setting::get('pay_through_remittance') !== '0')><span></span></span> Pay only through Remittance Statements</label>
          <span class="hint">Off: the treasury pays each transaction from its own page, as before.</span></div>
        <div class="field" style="grid-column:span 12"><label for="s_to">Send uploader files to</label><input id="s_to" type="email" class="input" name="uploader_email_to" value="{{ old('uploader_email_to', \App\Models\Setting::get('uploader_email_to')) }}"></div>
        <div class="field" style="grid-column:span 12"><label for="s_cc">Copy to</label><input id="s_cc" class="input" name="uploader_email_cc" value="{{ old('uploader_email_cc', \App\Models\Setting::get('uploader_email_cc')) }}" placeholder="Separate addresses with commas"></div>
      </div>
    </div>
  </div>
  <div class="panel" style="margin-top:18px;max-width:720px">
    <div class="panel-head"><h2>Transaction number counters</h2><span class="right hint">Last reserved: <span class="ref">{{ $last ?: '—' }}</span></span></div>
    <div class="panel-body">
      <p class="hint" style="margin-bottom:12px">The next serial handed out for each year. Numbers already used are always skipped, so raising the counter is safe; lowering it only fills gaps.</p>
      <table class="tbl"><thead><tr><th>Year</th><th>Next number</th><th>Will be</th></tr></thead><tbody>
      @foreach($counters as $c)
        <tr x-data="{ n: @js($c->next_number) }">
          <td><b>{{ $c->year }}</b></td>
          <td style="width:180px"><input type="number" min="1" class="input num" name="counters[{{ $c->year }}]" x-model="n" placeholder="Automatic"></td>
          <td><span class="ref" x-text="n ? 'T{{ sprintf('%02d', $c->year % 100) }}' + String(n).padStart(5, '0') : 'continues after the highest used'"></span></td>
        </tr>
      @endforeach
      </tbody></table>
    </div>
  </div>
</form>
@endsection
