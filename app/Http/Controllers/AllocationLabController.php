<?php

namespace App\Http\Controllers;

use App\Services\AllocationLab as Lab;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/** Allocation lab: test area for the allocation of shared expenses and shared staff. */
class AllocationLabController extends Controller
{
    protected function see(Request $r): void
    {
        $u = $r->user();
        abort_unless($u->hasPerm('costs.view') || $u->hasPerm('costs.manage') || $u->hasPerm('payroll.view') || $u->hasPerm('payroll.manage'), 403);
    }

    protected function edit(Request $r): void
    {
        abort_unless($r->user()->hasPerm('costs.manage') || $r->user()->hasPerm('payroll.manage'), 403);
    }

    public function index(Request $r)
    {
        $this->see($r);
        $period = Lab::period($r->input('period'));
        $cats = Lab::categories();
        return view('lab.index', [
            'period' => $period, 'tab' => $r->input('tab', 'guide'), 'cats' => $cats,
            'expLines' => Lab::lines($period, 'expense')->groupBy('category'),
            'staffLines' => Lab::lines($period, 'staff')->groupBy('staff'),
            'staff' => DB::table('lab_staff')->get(), 'people' => \App\Models\Individual::whereIn('id', DB::table('lab_staff')->pluck('individual_id'))->get()->keyBy('id'),
            'expenses' => DB::table('lab_expenses')->whereDate('period', $period->toDateString())->orderBy('category_code')->get(),
            'res' => Lab::compute($period), 'canEdit' => $r->user()->hasPerm('costs.manage') || $r->user()->hasPerm('payroll.manage'),
        ]);
    }

    public function candidates(Request $r)
    {
        $this->see($r);
        $period = Lab::period($r->input('period'));
        if ($r->filled('category')) {
            $taken = DB::table('lab_lines')->where('kind', 'expense')->where('category_code', $r->input('category'))->pluck('budget_line_id')->all();
            $list = Lab::candidates($r->input('category'), $period);
        } else {
            $taken = DB::table('lab_lines')->where('kind', 'staff')->where('lab_staff_id', $r->input('staff'))->pluck('budget_line_id')->all();
            $list = Lab::staffCandidates($r->input('office', 'Syria'), $period, (string) $r->input('q', ''));
        }
        return response()->json($list->map(fn ($x) => $x + ['taken' => in_array($x['id'], $taken)])->values());
    }

    public function addLines(Request $r)
    {
        $this->edit($r);
        $v = $r->validate(['kind' => 'required|in:expense,staff', 'category' => 'nullable|string', 'staff' => 'nullable|integer', 'ids' => 'required|array|min:1', 'ids.*' => 'integer']);
        $n = Lab::addLines($v['kind'], $v['ids'], $v['category'] ?? null, $v['staff'] ?? null);
        return back()->with('ok', "$n lines added.");
    }

    public function updateLine(Request $r, int $id)
    {
        $this->edit($r);
        $v = $r->validate(['total' => 'required|numeric|min:0', 'spent' => 'required|numeric|min:0']);
        DB::table('lab_lines')->where('id', $id)->update($v + ['updated_at' => now()]);
        return back()->with('ok', 'Line saved.');
    }

    public function deleteLine(Request $r, int $id)
    {
        $this->edit($r);
        DB::table('lab_lines')->where('id', $id)->delete();
        return back()->with('ok', 'Line removed.');
    }

    public function addStaff(Request $r)
    {
        $this->edit($r);
        $v = $r->validate(['individual_id' => 'required|exists:individuals,id', 'office' => 'required|in:Syria,Turkey', 'salary' => 'required|numeric|min:0']);
        if (DB::table('lab_staff')->where('individual_id', $v['individual_id'])->exists()) {
            return back()->withErrors(['individual_id' => 'This employee is already in the test. Change his salary below.']);
        }
        DB::table('lab_staff')->insert($v + ['created_at' => now(), 'updated_at' => now()]);
        return back()->with('ok', 'Employee added. Now add the project lines that pay him.');
    }

    public function updateStaff(Request $r, int $id)
    {
        $this->edit($r);
        $v = $r->validate(['salary' => 'required|numeric|min:0', 'office' => 'required|in:Syria,Turkey']);
        DB::table('lab_staff')->where('id', $id)->update($v + ['updated_at' => now()]);
        return back()->with('ok', 'Saved.');
    }

    public function deleteStaff(Request $r, int $id)
    {
        $this->edit($r);
        DB::table('lab_lines')->where('kind', 'staff')->where('lab_staff_id', $id)->delete();
        DB::table('lab_staff')->where('id', $id)->delete();
        return back()->with('ok', 'Employee removed.');
    }

    public function addExpense(Request $r)
    {
        $this->edit($r);
        $v = $r->validate(['period' => 'required|date_format:Y-m', 'category_code' => 'required|string|max:40', 'amount' => 'required|numeric', 'description' => 'nullable|string|max:250']);
        DB::table('lab_expenses')->insert(['period' => Lab::period($v['period'])->toDateString(), 'category_code' => $v['category_code'], 'amount' => $v['amount'],
            'description' => $v['description'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
        return back()->with('ok', 'Expense added.');
    }

    public function importExpenses(Request $r)
    {
        $this->edit($r);
        $r->validate(['period' => 'required|date_format:Y-m', 'file' => 'required|file|mimes:xlsx|max:10240']);
        [$n, $err] = Lab::importReport(Lab::period($r->input('period')), $r->file('file')->getRealPath());
        return $err ? back()->withErrors(['file' => $err]) : back()->with('ok', "$n expense lines uploaded (they replace the expenses of the month).");
    }

    public function deleteExpense(Request $r, int $id)
    {
        $this->edit($r);
        DB::table('lab_expenses')->where('id', $id)->delete();
        return back()->with('ok', 'Expense removed.');
    }

    public function export(Request $r)
    {
        $this->see($r);
        $period = Lab::period($r->input('period'));
        $res = Lab::compute($period);
        $path = storage_path('app/tmp/' . uniqid('lab_') . '.xlsx');
        @mkdir(dirname($path), 0775, true);
        $w = new Writer();
        $w->openToFile($path);
        $b = (new Style())->setFontBold();
        $w->getCurrentSheet()->setName('By project');
        $w->addRow(Row::fromValues(['Allocation lab - ' . $period->format('M Y')], $b));
        $w->addRow(Row::fromValues(['Project', 'Name', 'Expenses USD', 'Staff USD', 'Total USD', 'Share'], $b));
        foreach ($res['projects'] as $p) {
            $w->addRow(Row::fromValues([$p['code'], $p['name'] . ($p['core'] ? ' (core)' : ''), round($p['expenses'], 2), round($p['staff'], 2), round($p['total'], 2), round($p['share'], 4)]));
        }
        $w->addNewSheetAndMakeItCurrent()->setName('Expenses');
        $w->addRow(Row::fromValues(['Category', 'Amount of the month', 'Project', 'Budget line', 'Monthly budget', 'Allocated'], $b));
        foreach ($res['cats'] as $cat => $c) {
            foreach ($c['lines'] as $l) {
                $w->addRow(Row::fromValues([$cat, $c['amount'], $l->project, $l->bl?->code, $l->monthly, $c['parts'][$l->id] ?? 0]));
            }
            $w->addRow(Row::fromValues([$cat, $c['amount'], Lab::CORE . ' (core)', $c['core']?->code, '', $c['rest']]));
        }
        $w->addNewSheetAndMakeItCurrent()->setName('Staff');
        $w->addRow(Row::fromValues(['Employee', 'Code', 'Salary USD', 'Type', 'Project', 'Budget line', 'Monthly budget', 'Allocated'], $b));
        foreach ($res['staff'] as $s) {
            foreach ($s['lines'] as $l) {
                $w->addRow(Row::fromValues([$s['person']?->name, $s['person']?->code, (float) $s['row']->salary, $s['type'], $l->project, $l->bl?->code, $l->monthly, $s['parts'][$l->id] ?? 0]));
            }
            if ($s['rest'] > 0) {
                $w->addRow(Row::fromValues([$s['person']?->name, $s['person']?->code, (float) $s['row']->salary, $s['type'], Lab::CORE . ' (core)', $s['core']?->code, '', $s['rest']]));
            }
        }
        $w->close();
        return response()->download($path, 'Allocation_Lab_' . $period->format('Y-m') . '.xlsx')->deleteFileAfterSend();
    }
}
