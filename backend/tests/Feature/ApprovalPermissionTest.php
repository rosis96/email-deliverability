<?php

namespace Tests\Feature;

use App\Models\Approval;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApprovalPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function user(string $email): User
    {
        return User::where('email', $email)->first();
    }

    public function test_viewer_cannot_request_an_approval(): void
    {
        Sanctum::actingAs($this->user('viewer@revcadence.local'));

        $this->postJson('/api/approvals', [
            'action_type' => 'reconnect_account',
            'proposed_action' => 'Reconnect 2 mailboxes',
        ])->assertForbidden();
    }

    public function test_operator_can_request_but_cannot_approve(): void
    {
        Sanctum::actingAs($this->user('operator@revcadence.local'));

        $create = $this->postJson('/api/approvals', [
            'action_type' => 'reconnect_account',
            'proposed_action' => 'Reconnect 2 mailboxes',
        ])->assertCreated();

        $id = $create->json('data.id');

        $this->postJson("/api/approvals/{$id}/approve")->assertForbidden();
    }

    public function test_manager_can_approve(): void
    {
        $approval = Approval::create([
            'action_type' => 'reconnect_account',
            'proposed_action' => 'Reconnect mailboxes',
            'status' => 'pending',
            'requested_by_user_id' => $this->user('operator@revcadence.local')->id,
            'requested_at' => now(),
        ]);

        Sanctum::actingAs($this->user('manager@revcadence.local'));

        $this->postJson("/api/approvals/{$approval->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');
    }
}
