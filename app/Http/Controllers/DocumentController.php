<?php

namespace App\Http\Controllers;

use App\Forms\FormRegistry;
use App\Models\Attachment;
use App\Models\Document;
use App\Models\FormType;
use App\Models\Reservation;
use App\Models\Setting;
use App\Models\TxnType;
use App\Services\FormView;
use App\Services\Workflow;
use App\Support\Print\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

class DocumentController extends Controller
{
    protected function guardView(Document $doc): void
    {
        abort_unless($doc->canView(auth()->user()), 403, 'You cannot open this document.');
    }

    protected function guardCreate(string $code): void
    {
        $f = FormRegistry::get($code);
        abort_unless($f->type->active && auth()->user()->hasPerm("form.$code.create"), 403, 'You cannot prepare this form.');
    }

    // ---------------------------------------------------------------- lists
    public function index(Request $r, string $code)
    {
        $form = FormRegistry::get($code);
        $u = $r->user();
        $base = Document::visibleTo($u)->where('form_code', $code);
        $q = (clone $base)->with(['user', 'currentSigner'])->orderByDesc('date')->orderByDesc('id');
        $state = $r->query('state');
        if ($state) {
            $q->where('state', $state);
        }
        if ($r->query('mine')) {
            $q->where('user_id', $u->id);
        }
        if ($s = trim((string) $r->query('q'))) {
            $q->where(fn ($w) => $w->where('number', 'like', "%$s%")->orWhere('description', 'like', "%$s%"));
        }
        $counts = (clone $base)->selectRaw('state, count(*) as n')->groupBy('state')->pluck('n', 'state')->all();
        return view('documents.index', ['form' => $form, 'docs' => $q->paginate(30)->withQueryString(),
            'counts' => $counts, 'state' => $state]);
    }

    public function inbox(Request $r)
    {
        $u = $r->user();
        $docs = Document::where('current_signer_id', $u->id)->whereIn('state', ['signing', 'to_pay'])
            ->with(['user'])->orderBy('submitted_at')->paginate(30);
        $returned = Document::where('user_id', $u->id)->where('state', 'draft')->latest('updated_at')->limit(10)->get();
        return view('documents.inbox', ['docs' => $docs, 'drafts' => $returned]);
    }

    // ---------------------------------------------------------------- entry screen
    protected function screen(Document $doc, bool $new = false)
    {
        $u = auth()->user();
        $form = $doc->form();
        $data = $doc->data ?: $form->defaults();
        if ($old = old('payload')) {
            $data = $form->normalize(json_decode($old, true) ?: [], true);
        }
        $data['_labels'] = $data['_labels'] ?? [];
        // a new advance request of a registered holder: the advance goes to their own account
        $hk = $form->settings()['advance']['holder'] ?? null;
        if ($new && $hk && empty($data[$hk]) && ($h = \App\Models\AdvanceHolder::where('user_id', $u->id)->where('active', true)->first())) {
            $data[$hk] = $h->id;
            $data['_labels'][$hk] = \App\Services\Lookup::label('advance_holders', $h->id);
        }
        // forms open to every user: the applicant is the person filling it
        if ($new && !empty($form->settings()['open_to_all']) && $form->field('applicant_id') && empty($data['applicant_id']) && $u->individual_id) {
            $data['applicant_id'] = $u->individual_id;
            $data['_labels']['applicant_id'] = \App\Services\Lookup::label('individuals', $u->individual_id);
        }
        $ft = FormType::byCode($doc->form_code);
        $ro = !$new && !$doc->canEdit($u);
        return view('documents.edit', [
            'doc' => $doc, 'form' => $form, 'new' => $new, 'ro' => $ro,
            'data' => $data, 'blocks' => FormView::blocks($form),
            'client' => FormView::clientConfig($form),
            'openNotes' => $new ? 0 : $doc->reviewComments()->where('status', '!=', 'closed')->count(),
            'freeNumbers' => Reservation::where('user_id', $u->id)->where('state', 'reserved')->orderBy('number')->get(),
            'txnTypes' => TxnType::where('active', true)->orderBy('sequence')->get(),
            'defaultType' => TxnType::where('form_code', $doc->form_code)->value('id'),
            'payType' => TxnType::where('name', 'Bill Payment')->value('id') ?? TxnType::orderBy('sequence')->value('id'),
            'chain' => $new ? $this->previewChain($doc) : $doc->signatures()->with('individual')->get(),
        ]);
    }

    /** Signature steps of a not yet saved document (from Forms Management). */
    protected function previewChain(Document $doc)
    {
        $ft = FormType::byCode($doc->form_code);
        $me = auth()->user()->individual;
        return ($ft ? $ft->roles()->where('active', true)->with(['slot', 'defaultIndividual'])->get() : collect())
            ->map(function ($r, $i) use ($me) {
                $s = new \App\Models\DocumentSignature(['role' => $r->name, 'is_preparer' => $r->is_preparer, 'print_label' => $r->print_label, 'print_label' => $r->print_label,
                    'is_payer' => $r->is_payer, 'required' => $r->required, 'state' => 'pending',
                    'individual_id' => $r->is_preparer ? $me?->id : $r->default_individual_id]);
                $s->id = -($i + 1);
                $s->setRelation('individual', $r->is_preparer ? $me : $r->defaultIndividual);
                return $s;
            });
    }

    public function create(string $code)
    {
        $this->guardCreate($code);
        $doc = new Document(['form_code' => $code, 'state' => 'draft', 'date' => now()]);
        $doc->user_id = auth()->id();
        return $this->screen($doc, true);
    }

    public function show(Document $doc)
    {
        $this->guardView($doc);
        $doc->load(['signatures.individual', 'attachments.user', 'events.user', 'paidReservation', 'reservation']);
        return $this->screen($doc);
    }

    protected function payload(Request $r): array
    {
        $p = json_decode((string) $r->input('payload', '{}'), true) ?: [];
        unset($p['_labels']);
        return $p;
    }

    protected function extra(Request $r, Document $doc): array
    {
        return $doc->extra ?? [];
    }

    public function store(Request $r, string $code)
    {
        $this->guardCreate($code);
        $doc = new Document(['form_code' => $code]);
        $u = $r->user();
        $doc = DB::transaction(function () use ($r, $doc, $u) {
            $doc = Workflow::save($doc, $this->payload($r), $this->extra($r, $doc), $u);
            $this->applySigners($r, $doc, true);
            if ($rid = $r->input('reservation_id')) {
                Workflow::attachNumber($doc, Reservation::findOrFail($rid), $u);
            }
            return $doc;
        });
        if ($r->input('then') === 'submit') {
            return $this->submit($r, $doc->refresh());
        }
        return redirect()->route('documents.show', $doc)->with('ok', 'Saved.');
    }

    /** Signer choices posted with the form: signer[<sig id>] = individual id (new docs use the step order). */
    protected function applySigners(Request $r, Document $doc, bool $byOrder = false): void
    {
        $in = (array) $r->input('signer', []);
        if (!$in) {
            return;
        }
        $u = $r->user();
        $sigs = $doc->signatures()->get()->values();
        foreach ($in as $key => $indId) {
            $s = $byOrder ? $sigs->get(abs((int) $key) - 1) : $sigs->firstWhere('id', (int) $key);
            if ($s && (!$s->is_preparer || $u->hasPerm('documents.manage'))) {
                $s->update(['individual_id' => $indId ?: null]);
            }
        }
    }

    public function update(Request $r, Document $doc)
    {
        abort_unless($doc->canEdit($r->user()), 403, 'This document can no longer be changed by you.');
        $u = $r->user();
        DB::transaction(function () use ($r, $doc, $u) {
            Workflow::save($doc, $this->payload($r), $this->extra($r, $doc), $u);
            $this->applySigners($r, $doc);
            if (($rid = $r->input('reservation_id')) && !$doc->number) {
                Workflow::attachNumber($doc, Reservation::findOrFail($rid), $u);
            }
        });
        if ($r->input('then') === 'submit') {
            return $this->submit($r, $doc->refresh());
        }
        return redirect()->route('documents.show', $doc)->with('ok', 'Saved.');
    }

    public function compute(Request $r, string $code)
    {
        $form = FormRegistry::get($code);
        $data = $form->normalize((array) $r->input('data', []), true);
        return response()->json(['data' => $data, 'errors' => $form->errors, 'locked' => array_keys($form->locked)]);
    }

    /** Reserve a new number (dialog) or use one of mine. */
    public function number(Request $r, Document $doc)
    {
        abort_unless($doc->canEdit($r->user()), 403);
        if ($rid = $r->input('reservation_id')) {
            Workflow::attachNumber($doc, Reservation::findOrFail($rid), $r->user());
        } else {
            $v = $r->validate(['date' => 'required|date', 'txn_type_id' => 'required|exists:txn_types,id',
                'project_id' => 'nullable|exists:projects,id', 'cost_center_id' => 'nullable|exists:cost_centers,id',
                'description' => 'required|string|max:250']);
            $res = Reservation::reserve($v, $r->user());
            Workflow::attachNumber($doc, $res, $r->user());
        }
        return back()->with('ok', 'Number ' . $doc->refresh()->number . ' assigned.');
    }

    // ---------------------------------------------------------------- workflow
    public function submit(Request $r, Document $doc)
    {
        Workflow::submit($doc, $r->user());
        return redirect()->route('documents.show', $doc)->with('ok', 'Sent for signature.');
    }

    public function sign(Request $r, Document $doc)
    {
        Workflow::sign($doc, $r->user());
        $doc->refresh();
        return redirect()->route('documents.show', $doc)->with('ok', $doc->state === 'done' ? 'Signed - the transaction is final.' : 'Signed.');
    }

    public function returnDoc(Request $r, Document $doc)
    {
        $v = $r->validate(['reason' => 'required|string|max:500']);
        Workflow::returnToPreparer($doc, $r->user(), $v['reason']);
        return redirect()->route('documents.inbox')->with('ok', $doc->number . ' returned to the preparer.');
    }

    public function reset(Request $r, Document $doc)
    {
        Workflow::resetDraft($doc, $r->user());
        return back()->with('ok', 'Back to draft.');
    }

    public function cancel(Request $r, Document $doc)
    {
        Workflow::cancel($doc, $r->user());
        return back()->with('ok', 'Cancelled.');
    }

    public function destroy(Request $r, Document $doc)
    {
        $u = $r->user();
        abort_unless(in_array($doc->state, ['draft', 'cancel'], true) && ($doc->canManage($u) || $doc->isOwner($u)), 403);
        DB::transaction(function () use ($doc, $u) {
            \App\Services\Advances::onJarDropped($doc, $u);
            Reservation::where('document_id', $doc->id)->get()->each->release();
            foreach ($doc->attachments as $a) {
                Storage::disk('local')->delete($a->path);
                $a->delete();
            }
            $doc->signatures()->delete();
            $doc->events()->delete();
            $doc->delete();
        });
        return redirect()->route('documents.index', $doc->form_code)->with('ok', 'Deleted.');
    }

    public function duplicate(Request $r, Document $doc)
    {
        $this->guardView($doc);
        $this->guardCreate($doc->form_code);
        $copy = Workflow::save(new Document(['form_code' => $doc->form_code]), $doc->data, $doc->extra ?? [], $r->user());
        // keep the signers chosen on the original (same step names)
        $old = $doc->signatures()->where('is_preparer', false)->pluck('individual_id', 'role');
        foreach ($copy->signatures()->where('is_preparer', false)->get() as $s) {
            if (!empty($old[$s->role])) {
                $s->update(['individual_id' => $old[$s->role]]);
            }
        }
        return redirect()->route('documents.show', $copy)->with('ok', 'Copy created - reserve a number for it.');
    }

    public function payment(Request $r, Document $doc)
    {
        $v = $r->validate(['paid_reservation_id' => 'nullable|exists:reservations,id', 'paid_amount' => 'nullable|numeric',
            'paid_rate' => 'nullable|numeric', 'paid_date' => 'nullable|date']);
        Workflow::savePayment($doc, $r->user(), $v + ['paid_amount' => null, 'paid_rate' => null, 'paid_date' => null]);
        if ($r->input('then') === 'sign') {
            return $this->sign($r, $doc->refresh());
        }
        return back()->with('ok', 'Payment details saved.');
    }

    public function paymentNumber(Request $r, Document $doc)
    {
        abort_unless($doc->isPayStep($r->user()), 403, 'Only the treasury / cashier of this document can reserve its payment number.');
        $v = $r->validate(['date' => 'required|date', 'txn_type_id' => 'required|exists:txn_types,id',
            'project_id' => 'nullable|exists:projects,id', 'cost_center_id' => 'nullable|exists:cost_centers,id',
            'description' => 'required|string|max:250']);
        $res = Reservation::reserve($v, $r->user());
        Workflow::savePayment($doc, $r->user(), ['paid_reservation_id' => $res->id, 'paid_amount' => $doc->paid_amount,
            'paid_rate' => $doc->paid_rate, 'paid_date' => $doc->paid_date?->toDateString() ?? $res->date->toDateString()]);
        return back()->with('ok', 'Payment number ' . $res->number . ' reserved and linked.');
    }

    // ---------------------------------------------------------------- outputs
    public function print(Document $doc, string $report)
    {
        $this->guardView($doc);
        abort_unless(array_key_exists($report, $doc->form()->reports), 404);
        $pdf = Pdf::render($doc, $report);
        $name = ($doc->number ?: 'draft') . '-' . \Illuminate\Support\Str::slug($doc->form()->reports[$report]) . '.pdf';
        return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="' . $name . '"']);
    }

    public function finalFile(Document $doc)
    {
        $this->guardView($doc);
        $tmp = storage_path('app/tmp/' . uniqid('final_'));
        @mkdir($tmp, 0775, true);
        $files = [];
        foreach ($doc->formType()->prints()->where('active', true)->where('in_final', true)->get() as $pi => $p) {
            file_put_contents($files[] = "$tmp/p$pi.pdf", Pdf::render($doc, (string) $p->id));
        }
        foreach ($doc->attachments as $i => $a) {
            $src = $a->fullPath();
            if (!is_file($src)) {
                continue;
            }
            if ($a->mime === 'application/pdf') {
                $files[] = $src;
            } else {
                Pdf::imageToPdf($src, $files[] = "$tmp/img$i.pdf");
            }
        }
        $out = "$tmp/final.pdf";
        if (count($files) === 1) {
            rename($files[0], $out);
        } else {
            Pdf::merge($files, $out);
        }
        return response()->download($out, ($doc->number ?: 'document') . '-Final.pdf')->deleteFileAfterSend();
    }

    public function uploader(Request $r, Document $doc)
    {
        $this->guardView($doc);
        $form = $doc->form();
        $x = $form->export($r->query('x'));
        abort_unless($x && $r->user()->hasPerm('form.' . $doc->form_code . '.uploader'), 404);
        abort_if($doc->state === 'draft' && !$doc->canManage($r->user()), 422, 'Submit the document first.');
        $path = storage_path('app/tmp/' . uniqid('upl_') . '.xlsx');
        @mkdir(dirname($path), 0775, true);
        $w = new Writer();
        $w->openToFile($path);
        $w->getCurrentSheet()->setName(mb_substr($x['sheet'] ?: $x['name'], 0, 31));
        $w->addRow(Row::fromValues($form->uploaderHeader($x['key'] ?? null), (new Style())->setFontBold()));
        foreach ($form->uploaderRows($doc, $x['key'] ?? null) as $row) {
            $w->addRow(Row::fromValues(array_map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v, $row)));
        }
        $w->close();
        $name = ($doc->number ?: 'draft') . '-' . str_replace(' ', '_', $x['name']) . '.xlsx';
        if ($doc->canManage($r->user()) && $r->query('mark')) {
            $doc->update(['uploaded' => true, 'uploaded_at' => now()]);
            Workflow::log($doc, 'Uploader generated - marked as uploaded.', 'upload');
        }
        if ($r->query('email') && config('mail.default') !== 'log') {
            $to = array_filter(array_map('trim', explode(',', (string) Setting::get('uploader_email_to'))));
            $cc = array_filter(array_map('trim', explode(',', (string) Setting::get('uploader_email_cc'))));
            Mail::raw("Uploader of {$doc->number} attached.", function ($m) use ($to, $cc, $path, $name, $doc, $x) {
                $m->to($to)->cc($cc)->subject($x['name'] . ' - ' . $doc->number)->attach($path, ['as' => $name]);
            });
            Workflow::log($doc, 'Uploader e-mailed to ' . implode(', ', $to) . '.', 'upload');
        }
        return response()->download($path, $name)->deleteFileAfterSend();
    }

    // ---------------------------------------------------------------- attachments
    public function attach(Request $r, Document $doc)
    {
        $u = $r->user();
        abort_unless($doc->canManage($u) || $doc->canEdit($u) || $doc->isPayStep($u), 403, 'You cannot add files to this document now.');
        $max = (int) (((float) Setting::get('max_attachment_mb')) * 1024);
        $r->validate(['files' => 'required|array|max:20', 'files.*' => "file|mimetypes:application/pdf,image/png,image/jpeg|max:$max"],
            ['files.*.mimetypes' => 'Only PDF, PNG or JPG files can be attached.', 'files.*.max' => "Each file must be at most " . Setting::get('max_attachment_mb') . ' MB.']);
        foreach ($r->file('files') as $f) {
            $path = $f->store('attachments/' . $doc->id, 'local');
            Attachment::create(['document_id' => $doc->id, 'name' => $f->getClientOriginalName(), 'path' => $path,
                'mime' => $f->getMimeType(), 'size' => $f->getSize(), 'user_id' => $u->id]);
        }
        Workflow::log($doc, count($r->file('files')) . ' file(s) attached.', 'attach', $u);
        return response()->json(['ok' => true]);
    }

    public function download(Attachment $attachment)
    {
        abort_unless(\App\Services\Files::canView($attachment, auth()->user()), 403);
        return response()->file($attachment->fullPath(), ['Content-Type' => $attachment->mime,
            'Content-Disposition' => 'inline; filename="' . addslashes($attachment->name) . '"']);
    }

    public function detach(Request $r, Attachment $attachment)
    {
        $u = $r->user();
        $doc = $attachment->document;
        abort_unless($doc && ($doc->canManage($u) || ($attachment->user_id === $u->id && ($doc->canEdit($u) || $doc->isPayStep($u)))), 403);
        Storage::disk('local')->delete($attachment->path);
        $attachment->delete();
        Workflow::log($doc, 'File ' . $attachment->name . ' removed.', 'attach', $u);
        return back()->with('ok', 'File removed.');
    }
}
