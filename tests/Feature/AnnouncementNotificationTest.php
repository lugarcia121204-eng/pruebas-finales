<?php

namespace Tests\Feature;

use App\Models\{Announcement, Notification, User};
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AnnouncementNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_announcement_notifications_work_when_some_recipient_roles_are_missing(): void
    {
        Mail::fake();
        Role::findOrCreate('Docente', 'web');
        Role::findOrCreate('Super Admin', 'web');
        $teacher = User::factory()->create();
        $teacher->assignRole('Docente');
        $operator = User::factory()->create();
        $operator->assignRole('Super Admin');
        $announcement = Announcement::create(['name' => 'Convocatoria de prueba']);
        app(NotificationService::class)->notifyNewAnnouncement($announcement->id);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame($teacher->id, Notification::firstOrFail()->user_id);
        Mail::assertQueued(\App\Mail\NewAnnouncement::class, 1);
    }
}
