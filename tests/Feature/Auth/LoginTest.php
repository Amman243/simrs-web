<?php

namespace Tests\Feature\Auth;

use App\Auth\KhanzaUser;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class LoginTest extends TestCase
{
    public function test_tamu_diarahkan_ke_login(): void
    {
        $this->get('/')->assertRedirect(route('login'));
        $this->get(route('login'))->assertOk()->assertSee('Masuk');
    }

    public function test_admin_khanza_bisa_login(): void
    {
        $this->assertTrue(
            DB::table('admin')->whereRaw("usere = AES_ENCRYPT('spv', 'nur')")->exists(),
            'Tes ini butuh admin default Khanza (spv/server) di sik_test.'
        );

        Livewire::test('pages::auth.login')
            ->set('id_user', 'spv')
            ->set('password', 'server')
            ->call('login')
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboard'));

        $user = Auth::user();
        $this->assertInstanceOf(KhanzaUser::class, $user);
        $this->assertTrue($user->isAdmin);
        $this->assertSame('Admin Utama', $user->kode);
    }

    public function test_password_salah_ditolak(): void
    {
        Livewire::test('pages::auth.login')
            ->set('id_user', 'spv')
            ->set('password', 'salah')
            ->call('login')
            ->assertHasErrors('id_user');

        $this->assertGuest();
    }

    public function test_user_biasa_dimuat_dari_tabel_user(): void
    {
        $id = DB::table('user')->selectRaw("AES_DECRYPT(id_user, 'nur') as id")->value('id');

        if (! $id) {
            $this->markTestSkipped('Tidak ada baris di tabel user.');
        }

        $user = Auth::createUserProvider('users')->retrieveById($id);

        $this->assertInstanceOf(KhanzaUser::class, $user);
        $this->assertFalse($user->isAdmin);
        $this->assertSame($id, $user->kode);
        $this->assertArrayHasKey('igd', $user->permissions);
    }

    public function test_dashboard_tampil_setelah_login(): void
    {
        $this->actingAsAdmin()->get('/')->assertOk()->assertSee('Selamat datang');
    }

    public function test_permission_dicek_di_server(): void
    {
        $this->actingAsKhanza(['igd' => false])->get(route('registrasi.index'))->assertForbidden();
        $this->actingAsKhanza(['igd' => true])->get(route('registrasi.index'))->assertOk();
    }

    public function test_logout(): void
    {
        $this->actingAsAdmin()->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();
    }
}
