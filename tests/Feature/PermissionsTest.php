<?php

namespace Tests\Feature;

use App\Models\Document;
use Tests\TestCase;

/** Who may do what: a clerk who prepares PTRs must not reach money, access or audit screens. */
class PermissionsTest extends TestCase
{
    public static function closed(): array
    {
        return array_map(fn ($u) => [$u], ['/admin/users', '/admin/roles', '/admin/settings', '/admin/forms', '/admin/audit', '/admin/rates',
            '/reconcile', '/reports', '/numbers/types']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('closed')]
    public function test_a_clerk_cannot_open(string $url): void
    {
        $this->actingAs($this->userWith(['form.ptr.create', 'numbers.reserve']))->get($url)->assertForbidden();
    }

    public function test_a_clerk_cannot_change_money_or_unlock(): void
    {
        $res = $this->reserve();
        $this->actingAs($this->admin())->post('/forms/ptr', ['payload' => $this->ptrPayload(), 'reservation_id' => $res->id]);
        $doc = Document::firstOrFail();
        $clerk = $this->userWith(['form.ptr.create', 'numbers.reserve']);
        $this->actingAs($clerk)->post('/documents/' . $doc->id . '/treasury', ['total' => 1])->assertForbidden();
        $this->actingAs($clerk)->post('/documents/' . $doc->id . '/unlock')->assertForbidden();
        $this->actingAs($clerk)->delete('/documents/' . $doc->id)->assertForbidden();
        $this->assertEqualsWithDelta(197884, $doc->fresh()->amount, 0.01);
    }

    public function test_a_clerk_can_prepare_a_ptr_with_his_own_number(): void
    {
        $clerk = $this->userWith(['form.ptr.create', 'numbers.reserve']);
        $res = $this->reserve($clerk);
        $this->actingAs($clerk)->post('/forms/ptr', ['payload' => $this->ptrPayload(), 'reservation_id' => $res->id])->assertSessionHasNoErrors();
        $this->assertSame('done', Document::firstOrFail()->state);
    }

    public function test_without_the_form_permission_nothing_can_be_prepared(): void
    {
        $u = $this->userWith(['numbers.reserve']);
        $this->actingAs($u)->get('/forms/ptr/new')->assertForbidden();
        $this->actingAs($u)->post('/forms/ptr', ['payload' => $this->ptrPayload()])->assertForbidden();
    }

    public function test_a_disabled_user_is_signed_out(): void
    {
        $u = $this->userWith(['form.ptr.create']);
        $u->update(['active' => false]);
        $this->actingAs($u)->get('/')->assertRedirect();
        $this->assertGuest();
    }
}
