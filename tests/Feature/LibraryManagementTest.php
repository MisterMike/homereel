<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Filament\Resources\Albums\Pages\ManageAlbums;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Filament\Resources\Videos\Pages\EditVideo;
use App\Filament\Resources\Videos\Pages\ListVideos;
use App\Models\Album;
use App\Models\User;
use App\Models\Video;
use App\Notifications\LibraryInvitation;
use App\Services\UserInvitations;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class LibraryManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function video(User $owner, array $attributes = []): Video
    {
        return Video::create(array_merge([
            'uuid' => (string) Str::uuid(), 'title' => 'A private video', 'uploaded_by' => $owner->id,
            'original_filename' => 'day.mp4', 'mime_type' => 'video/mp4', 'file_size' => 10,
            'status' => VideoStatus::Ready, 'storage_provider' => 'local', 'storage_path' => 'originals/test',
        ], $attributes));
    }

    public function test_admin_can_invite_a_contributor_from_the_user_panel(): void
    {
        Notification::fake();
        $this->actingAs($this->admin());

        Livewire::test(ManageUsers::class)->callAction('inviteUser', data: [
            'name' => 'A family member', 'email' => 'member@example.test', 'role' => 'contributor',
        ])->assertHasNoActionErrors();

        $user = User::where('email', 'member@example.test')->firstOrFail();
        $this->assertTrue($user->is_contributor);
        $this->assertFalse($user->is_admin);
        $this->assertFalse($user->canUploadVideos());
        Notification::assertSentTo($user, LibraryInvitation::class);
        $this->assertArrayNotHasKey('invitation_token', $user->toArray());
        $this->get('/admin/users')->assertOk()->assertSee('one shared library');
    }

    public function test_invitation_sets_a_password_and_cannot_be_replayed(): void
    {
        Notification::fake();
        $user = app(UserInvitations::class)->invite($this->admin(), ['name' => 'Guest', 'email' => 'guest@example.test', 'role' => 'viewer']);
        $invitation = Notification::sent($user, LibraryInvitation::class)->first();
        $this->get($invitation->url)->assertOk()->assertHeader('Referrer-Policy', 'no-referrer');
        $this->post($invitation->url, ['password' => 'short', 'password_confirmation' => 'different'])->assertSessionHasErrors('password');
        $this->assertNotNull($user->fresh()->invitation_token);

        $this->post($invitation->url, ['password' => 'family-password-2026', 'password_confirmation' => 'family-password-2026'])->assertRedirect(route('videos.index'));

        $this->assertAuthenticatedAs($user);
        $this->assertTrue(Hash::check('family-password-2026', $user->fresh()->password));
        $this->assertNull($user->fresh()->invitation_token);
        $this->post('/logout');
        $this->post($invitation->url, ['password' => 'replacement-password', 'password_confirmation' => 'replacement-password'])->assertGone();
        $this->assertTrue(Hash::check('family-password-2026', $user->fresh()->password));
        Notification::assertSentToTimes($user, LibraryInvitation::class, 1);
    }

    public function test_expired_replaced_and_disabled_invitation_links_return_410(): void
    {
        $this->freezeTime();
        Notification::fake();
        $admin = $this->admin();
        $user = app(UserInvitations::class)->invite($admin, ['name' => 'Guest', 'email' => 'guest@example.test', 'role' => 'viewer']);
        $original = Notification::sent($user, LibraryInvitation::class)->first()->url;
        $this->travel(8)->days();
        $this->get($original)->assertGone();

        app(UserInvitations::class)->resend($admin, $user);

        $replacement = Notification::sent($user, LibraryInvitation::class)->last()->url;
        $this->get($original)->assertGone();
        $this->get($replacement)->assertOk();
        $user->forceFill(['is_active' => false])->save();
        $this->post($replacement, ['password' => 'family-password-2026', 'password_confirmation' => 'family-password-2026'])->assertGone();
        Notification::assertSentToTimes($user, LibraryInvitation::class, 2);
    }

    public function test_contributors_can_upload_and_manage_only_their_own_videos(): void
    {
        $owner = User::factory()->create(['is_contributor' => true]);
        $own = $this->video($owner);
        $other = $this->video($this->admin());
        $album = Album::factory()->create();
        $this->actingAs($owner);

        $this->postJson('/uploads', ['filename' => 'day.mp4', 'title' => 'A day', 'size' => 10, 'mime_type' => 'video/mp4'])->assertCreated();
        $this->get('/admin/upload-video')->assertOk()->assertSee('Upload another video');
        Livewire::test(ListVideos::class)->assertCanSeeTableRecords([$own])->assertCanNotSeeTableRecords([$other]);
        Livewire::test(EditVideo::class, ['record' => $own->uuid])->fillForm(['title' => 'Updated by contributor', 'album_id' => $album->id, 'prototype_available' => true])->call('save')->assertHasNoFormErrors();

        $this->assertDatabaseHas('videos', ['id' => $own->id, 'title' => 'Updated by contributor', 'album_id' => $album->id, 'prototype_available' => true]);
        $this->get('/admin/videos/'.$other->uuid.'/edit')->assertNotFound();
        $this->get('/videos/'.$other->uuid.'/stream')->assertForbidden();
        $this->get('/admin/users')->assertForbidden();
        $this->get('/admin/albums')->assertForbidden();
        $this->get('/admin/video-settings')->assertForbidden();
    }

    public function test_admin_can_organize_and_remove_albums_without_deleting_videos(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        Livewire::test(ManageAlbums::class)->callAction('create', data: ['name' => 'Holidays'])->assertHasNoActionErrors();
        $album = Album::where('name', 'Holidays')->firstOrFail();
        $video = $this->video($admin, ['album_id' => $album->id]);

        Livewire::test(ManageAlbums::class)->callAction(TestAction::make('delete')->table($album))->assertHasNoActionErrors();

        $this->assertModelMissing($album);
        $this->assertModelExists($video);
        $this->assertNull($video->fresh()->album_id);
    }

    public function test_album_filter_only_shows_albums_containing_authorized_videos(): void
    {
        $admin = $this->admin();
        $shared = Album::factory()->create(['name' => 'Summer memories']);
        $private = Album::factory()->create(['name' => 'Private archive']);
        $this->video($admin, ['title' => 'A shared summer', 'album_id' => $shared->id, 'prototype_available' => true]);
        $this->video($admin, ['album_id' => $private->id]);
        $this->video($admin, ['title' => 'Outside album', 'prototype_available' => true]);
        $this->actingAs(User::factory()->create());

        $this->get('/?album='.$shared->id)->assertOk()->assertSee('A shared summer')->assertDontSee('Outside album')->assertDontSee('Private archive');
        $this->get('/?album='.$private->id)->assertNotFound();
        $this->get('/?album=invalid')->assertSessionHasErrors('album');
    }

    public function test_disabled_users_cannot_login_or_continue_existing_sessions(): void
    {
        $user = User::factory()->create(['is_active' => false]);

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->actingAs($user)->get('/')->assertForbidden();
        $this->postJson('/uploads')->assertForbidden();
        $this->post('/logout')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_admin_can_change_roles_and_disable_access_but_cannot_lock_themselves_out(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $this->actingAs($admin);

        Livewire::test(ManageUsers::class)->callAction(TestAction::make('edit')->table($user), data: ['name' => 'Updated name', 'role' => 'contributor', 'is_active' => false])->assertHasNoActionErrors();
        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Updated name', 'is_contributor' => true, 'is_active' => false]);
        Livewire::test(ManageUsers::class)->callAction(TestAction::make('edit')->table($admin), data: ['name' => $admin->name, 'role' => 'viewer', 'is_active' => true])->assertHasActionErrors(['role']);
        $this->assertTrue($admin->fresh()->is_admin);
    }

    public function test_inviting_an_existing_email_does_not_change_the_account_or_send_mail(): void
    {
        Notification::fake();
        $existing = User::factory()->create();
        $this->actingAs($this->admin());

        Livewire::test(ManageUsers::class)->callAction('inviteUser', data: ['name' => 'Replacement', 'email' => $existing->email, 'role' => 'administrator'])->assertHasActionErrors(['email']);

        $this->assertFalse($existing->fresh()->is_admin);
        Notification::assertNothingSent();
    }

    public function test_non_administrators_cannot_invite_users_or_manage_other_accounts(): void
    {
        Notification::fake();
        $contributor = User::factory()->create(['is_contributor' => true]);
        $viewer = User::factory()->create();
        $this->actingAs($contributor);

        Livewire::test(ManageUsers::class)->assertForbidden();
        $this->actingAs($viewer)->get('/admin/users')->assertForbidden();

        $this->assertDatabaseCount('users', 2);
        Notification::assertNothingSent();
    }

    public function test_video_policy_grants_management_to_administrators_and_the_contributing_owner(): void
    {
        $admin = $this->admin();
        $owner = User::factory()->create(['is_contributor' => true]);
        $other = User::factory()->create(['is_contributor' => true]);
        $viewer = User::factory()->create();
        $video = $this->video($owner);

        $this->assertTrue($admin->can('update', $video));
        $this->assertTrue($owner->can('update', $video));
        $this->assertTrue($owner->can('delete', $video));
        $this->assertFalse($other->can('update', $video));
        $this->assertFalse($other->can('delete', $video));
        $this->assertFalse($viewer->can('update', $video));
        $this->assertFalse($viewer->can('view', $video));
        $video->update(['prototype_available' => true]);
        $this->assertTrue($viewer->can('view', $video));
    }

    public function test_album_and_invitation_names_are_escaped(): void
    {
        $script = '<script>alert(1)</script>';
        $album = Album::factory()->create(['name' => $script]);
        $this->video($this->admin(), ['album_id' => $album->id, 'prototype_available' => true]);
        $viewer = User::factory()->create();
        $this->actingAs($viewer)->get('/?album='.$album->id)->assertOk()->assertSee($script)->assertDontSee($script, false);
        $this->post('/logout');
        Notification::fake();
        $user = app(UserInvitations::class)->invite($this->admin(), ['name' => $script, 'email' => 'guest@example.test', 'role' => 'viewer']);
        $invitation = Notification::sent($user, LibraryInvitation::class)->first();

        $this->get($invitation->url)->assertOk()->assertSee($script)->assertDontSee($script, false);

        $html = (string) $invitation->toMail($user)->render();
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString($script, $html);
        Notification::assertSentTo($user, LibraryInvitation::class);
    }

    public function test_search_matches_titles_and_descriptions_without_revealing_private_videos(): void
    {
        $admin = $this->admin();
        $album = Album::factory()->create(['name' => 'Summer trips']);
        $this->video($admin, ['title' => 'Mountain walk', 'album_id' => $album->id, 'prototype_available' => true]);
        $this->video($admin, ['title' => 'A day outside', 'description' => 'A mountain picnic', 'prototype_available' => true]);
        $this->video($admin, ['title' => 'Private footage', 'description' => 'Mountain holiday']);
        $this->actingAs(User::factory()->create());

        $this->get('/?q=mountain')->assertOk()->assertSee('Mountain walk')->assertSee('A day outside')->assertDontSee('Private footage');
        $this->get('/?q=mountain&album='.$album->id)->assertOk()->assertSee('Mountain walk')->assertDontSee('A day outside')->assertDontSee('Private footage');
        $this->get('/?q=unmatched')->assertOk()->assertSee('No matching videos.')->assertDontSee('Upload your first video');
        $this->get('/?q[]=invalid')->assertSessionHasErrors('q');
    }

    public function test_album_navigation_counts_only_visible_videos_and_stays_available_while_watching(): void
    {
        $admin = $this->admin();
        $album = Album::factory()->create(['name' => 'Shared adventures']);
        $privateAlbum = Album::factory()->create(['name' => 'Hidden album']);
        $shared = $this->video($admin, ['album_id' => $album->id, 'prototype_available' => true]);
        $this->video($admin, ['album_id' => $album->id]);
        $this->video($admin, ['album_id' => $privateAlbum->id]);
        $this->actingAs(User::factory()->create());

        $response = $this->get('/?album='.$album->id)->assertOk()->assertSee('Shared adventures')->assertDontSee('Hidden album')->assertDontSee('Manage people');
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $links = (new \DOMXPath($document))->query('//aside//a[@aria-current="page"]');
        $this->assertCount(1, $links);
        $this->assertSame('Shared adventures1', preg_replace('/\s+/', ' ', trim($links->item(0)->textContent)));
        $this->get(route('videos.show', $shared))->assertOk()->assertSee(route('videos.index', ['album' => $album->id]), false)->assertDontSee('Hidden album');
    }
}
