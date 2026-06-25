<?php
// tests/Feature/Realtime/FirebaseTokenTest.php
namespace Tests\Feature\Realtime;

use App\Models\User;
use Kreait\Firebase\Contract\Auth;
use Mockery;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class FirebaseTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_get_token(): void
    {
        $this->getJson('/firebase/token')->assertUnauthorized();
    }

    public function test_authenticated_user_gets_a_token(): void
    {
        $user = User::factory()->create();

        $tokenObj = Mockery::mock(\Lcobucci\JWT\UnencryptedToken::class);
        $tokenObj->shouldReceive('toString')->andReturn('fake.custom.token');

        $auth = Mockery::mock(Auth::class);
        $auth->shouldReceive('createCustomToken')->once()
            ->with((string) $user->id, Mockery::type('array'))
            ->andReturn($tokenObj);
        $this->app->instance(Auth::class, $auth);

        $this->actingAs($user)->getJson('/firebase/token')
            ->assertOk()
            ->assertJson(['token' => 'fake.custom.token']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
