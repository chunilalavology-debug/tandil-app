<?php

namespace Tests\Feature\Web;

use App\Mail\AccountDeletionRequestMail;
use App\Models\AccountDeletionRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AccountDeletionRequestPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_delete_account_page_is_public(): void
    {
        $response = $this->get('/delete-account');

        $response->assertOk();
        $response->assertSee('Account Deletion Request', false);
        $response->assertSee('How to request deletion', false);
        $response->assertSee('Data that will be deleted', false);
        $response->assertSee('Data retained for legal or accounting purposes', false);
        $response->assertSee('Delete from the Android app', false);
        $response->assertSee('Submit a deletion request', false);
        $response->assertSee('5 years', false);
    }

    public function test_guest_can_submit_deletion_request(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'email' => 'client.delete@example.com',
            'phone' => '+971501112233',
        ]);

        $response = $this->post('/delete-account', [
            'full_name' => 'Test Client',
            'email' => 'client.delete@example.com',
            'phone' => '+971501112233',
            'account_type' => 'client',
            'reason' => 'No longer need the app',
            'confirm_deletion' => '1',
            'website' => '',
        ]);

        $response->assertRedirect('/delete-account');
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('account_deletion_requests', [
            'email' => 'client.delete@example.com',
            'user_id' => $user->id,
            'status' => AccountDeletionRequest::STATUS_PENDING,
        ]);

        Mail::assertSent(AccountDeletionRequestMail::class);
    }

    public function test_submission_requires_confirmation(): void
    {
        Mail::fake();

        $response = $this->from('/delete-account')->post('/delete-account', [
            'full_name' => 'Test Client',
            'email' => 'client.delete@example.com',
            'account_type' => 'client',
        ]);

        $response->assertRedirect('/delete-account');
        $response->assertSessionHasErrors('confirm_deletion');
        $this->assertDatabaseCount('account_deletion_requests', 0);
        Mail::assertNothingSent();
    }

    public function test_honeypot_blocks_bots(): void
    {
        Mail::fake();

        $response = $this->from('/delete-account')->post('/delete-account', [
            'full_name' => 'Bot User',
            'email' => 'bot@example.com',
            'account_type' => 'client',
            'confirm_deletion' => '1',
            'website' => 'http://spam.example',
        ]);

        $response->assertRedirect('/delete-account');
        $response->assertSessionHasErrors('website');
        $this->assertDatabaseCount('account_deletion_requests', 0);
    }
}
