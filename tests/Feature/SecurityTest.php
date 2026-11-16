<?php

namespace Tests\Feature;

use App\Models\Document;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** Login protection, uploads, passwords. */
class SecurityTest extends TestCase
{
    public function test_six_wrong_passwords_lock_the_login(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->post('/login', ['email' => 'admin@violet.local', 'password' => 'wrong-' . $i]);
        }
        $this->post('/login', ['email' => 'admin@violet.local', 'password' => 'ChangeMe!2026'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_the_right_password_signs_in(): void
    {
        $this->post('/login', ['email' => 'admin@violet.local', 'password' => 'ChangeMe!2026'])->assertRedirect();
        $this->assertAuthenticated();
    }

    public function test_uploads_accept_only_pdf_and_images(): void
    {
        $res = $this->reserve();
        $this->actingAs($this->admin())->post('/forms/ptr', ['payload' => $this->ptrPayload(), 'reservation_id' => $res->id]);
        $doc = Document::firstOrFail();
        $real = function (string $name, string $content) {          // a real file: its type is read from the content, not the name
            $p = tempnam(sys_get_temp_dir(), 'up');
            file_put_contents($p, $content);
            return new UploadedFile($p, $name, 'application/pdf', null, true);
        };
        $php = $real('invoice.pdf', '<?php echo "x"; ?>');          // a script named .pdf
        $this->post('/documents/' . $doc->id . '/attachments', ['files' => [$php]])->assertSessionHasErrors();
        $this->assertSame(0, $doc->attachments()->count());
        $pdf = $real('invoice.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
        $this->post('/documents/' . $doc->id . '/attachments', ['files' => [$pdf]])->assertSessionHasNoErrors();
        $this->assertSame(1, $doc->attachments()->count());
    }

    public function test_a_short_password_is_refused(): void
    {
        $this->actingAs($this->admin())->post('/account/password', ['current' => 'ChangeMe!2026', 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertSessionHasErrors();
    }

    public function test_pages_do_not_leak_errors_in_production_mode(): void
    {
        config(['app.debug' => false]);
        $this->actingAs($this->admin())->get('/documents/999999')->assertNotFound()->assertDontSee('Stack trace');
    }
}
