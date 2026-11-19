<?php
namespace Tests\Feature;
use App\Models\Announcement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
class AnnouncementPreviewTest extends TestCase
{
    use RefreshDatabase;
    public function test_preview_requires_permission_and_serves_the_attached_file(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('announcements/example.pdf', '%PDF-1.4 example');
        $announcement = Announcement::create(['name' => 'Preview', 'file_path' => 'announcements/example.pdf']);
        $user = User::factory()->create();
        $url = route('announcements.preview', $announcement);
        $this->actingAs($user)->get($url)->assertForbidden();
        Permission::findOrCreate('announcements.index', 'web');
        $user->givePermissionTo('announcements.index');
        $this->actingAs($user->fresh())->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        Storage::disk('public')->delete('announcements/example.pdf');
        $this->get($url)->assertNotFound();
    }
    public function test_teacher_can_preview_active_and_closed_announcements(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('announcements/teacher.pdf', '%PDF-1.4 teacher');
        $announcement = Announcement::create([
            'name' => 'Teacher preview',
            'status' => 'activa',
            'file_path' => 'announcements/teacher.pdf',
        ]);
        $user = User::factory()->create();
        $url = route('teacher.announcements.preview', $announcement->id);
        $this->actingAs($user)->get($url)->assertForbidden();
        \Spatie\Permission\Models\Role::findOrCreate('Docente', 'web');
        $user->assignRole('Docente');
        $this->actingAs($user->fresh())->get($url)->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $announcement->update(['status' => 'cerrada']);
        $this->get($url)->assertOk();
        $announcement->update(['status' => 'pendiente']);
        $this->get($url)->assertNotFound();
        $announcement->update(['status' => 'activa']);
        Storage::disk('public')->delete('announcements/teacher.pdf');
        $this->get($url)->assertNotFound();
    }

    public function test_teacher_announcements_include_closed_history_and_exclude_pending_announcements(): void
    {
        $active = Announcement::create(['name' => 'Activa', 'status' => 'activa']);
        $closed = Announcement::create(['name' => 'Cerrada', 'status' => 'cerrada']);
        Announcement::create(['name' => 'Pendiente', 'status' => 'pendiente']);

        $teacher = User::factory()->create();
        \Spatie\Permission\Models\Role::findOrCreate('Docente', 'web');
        $teacher->assignRole('Docente');

        $this->actingAs($teacher)->get(route('teacher.announcements.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Teacher/Announcements/Index')
                ->has('announcements.data', 1)
                ->where('announcements.data.0.id', $active->id)
                ->has('closedAnnouncements.data', 1)
                ->where('closedAnnouncements.data.0.id', $closed->id));
    }
    public function test_public_preview_exposes_published_announcements_only(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('announcements/public.pdf', '%PDF-1.4 public');
        $announcement = Announcement::create([
            'name' => 'Public', 'status' => 'activa',
            'file_path' => 'announcements/public.pdf',
        ]);
        $url = route('announcement.preview', $announcement);
        $this->get($url)->assertOk();
        $announcement->update(['status' => 'cerrada']);
        $this->get($url)->assertOk();
        $announcement->update(['status' => 'pendiente']);
        $this->get($url)->assertNotFound();
        $announcement->update(['status' => 'activa']);
        Storage::disk('public')->delete('announcements/public.pdf');
        $this->get($url)->assertNotFound();
    }
}
