<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Office;
use App\Models\Room;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookingRulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_allows_valid_booking_creation_with_authenticated_user(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $room = Room::factory()->for(
            Office::factory()->state(['name' => 'Москва', 'timezone' => 'Europe/Moscow'])
        )->create();

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/bookings', [
            'room_id' => $room->id,
            'user_id' => $otherUser->id,
            'title' => 'Планёрка',
            'starts_at' => '2026-11-02T10:00:00+03:00',
            'ends_at' => '2026-11-02T11:00:00+03:00',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('user_id', $user->id)
            ->assertJsonPath('room_id', $room->id);

        $this->assertDatabaseHas('bookings', [
            'user_id' => $user->id,
            'room_id' => $room->id,
            'title' => 'Планёрка',
        ]);

        $this->assertDatabaseMissing('bookings', ['user_id' => $otherUser->id]);
    }

    public function test_it_rejects_overlaps_and_allows_touching_slots(): void
    {
        $user = User::factory()->create();
        $room = Room::factory()->for(
            Office::factory()->state(['name' => 'Москва', 'timezone' => 'Europe/Moscow'])
        )->create();

        Sanctum::actingAs($user);

        Booking::create([
            'room_id' => $room->id,
            'user_id' => $user->id,
            'title' => 'Существующая',
            'starts_at' => $this->toUtc('2026-11-02T10:00:00+03:00'),
            'ends_at' => $this->toUtc('2026-11-02T11:00:00+03:00'),
        ]);

        $this->postJson('/api/bookings', [
            'room_id' => $room->id,
            'title' => 'Пересечение',
            'starts_at' => '2026-11-02T10:30:00+03:00',
            'ends_at' => '2026-11-02T11:30:00+03:00',
        ])->assertStatus(409);

        $this->postJson('/api/bookings', [
            'room_id' => $room->id,
            'title' => 'Впритык',
            'starts_at' => '2026-11-02T11:00:00+03:00',
            'ends_at' => '2026-11-02T12:00:00+03:00',
        ])->assertOk();
    }

    public function test_it_validates_duration_and_working_hours_with_local_timezone(): void
    {
        $user = User::factory()->create();
        $room = Room::factory()->for(
            Office::factory()->state(['name' => 'Москва', 'timezone' => 'Europe/Moscow'])
        )->create();

        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', [
            'room_id' => $room->id,
            'title' => 'Неправильная длительность',
            'starts_at' => '2026-11-02T10:00:00+03:00',
            'ends_at' => '2026-11-02T10:10:00+03:00',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['starts_at', 'ends_at']);

        $this->postJson('/api/bookings', [
            'room_id' => $room->id,
            'title' => 'Не в рабочем интервале',
            'starts_at' => '2026-11-02T08:00:00+03:00',
            'ends_at' => '2026-11-02T08:30:00+03:00',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['starts_at', 'ends_at']);

        $this->postJson('/api/bookings', [
            'room_id' => $room->id,
            'title' => 'Таймзона в offset',
            'starts_at' => '2026-11-02T06:00:00Z',
            'ends_at' => '2026-11-02T07:00:00Z',
        ])->assertOk();
    }

    public function test_it_allows_weekend_and_other_dates_within_office_hours(): void
    {
        $user = User::factory()->create();
        $room = Room::factory()->for(
            Office::factory()->state(['name' => 'Москва', 'timezone' => 'Europe/Moscow'])
        )->create();

        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', [
            'room_id' => $room->id,
            'title' => 'Выходной день',
            'starts_at' => '2026-12-05T10:00:00+03:00',
            'ends_at' => '2026-12-05T11:00:00+03:00',
        ])->assertOk();
    }

    public function test_only_author_can_cancel_booking(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $room = Room::factory()->for(
            Office::factory()->state(['name' => 'Москва', 'timezone' => 'Europe/Moscow'])
        )->create();

        $booking = Booking::create([
            'room_id' => $room->id,
            'user_id' => $owner->id,
            'title' => 'Чужая бронь',
            'starts_at' => $this->toUtc('2026-11-02T10:00:00+03:00'),
            'ends_at' => $this->toUtc('2026-11-02T11:00:00+03:00'),
        ]);

        Sanctum::actingAs($otherUser);

        $this->deleteJson('/api/bookings/'.$booking->id)
            ->assertForbidden();

        $this->assertDatabaseHas('bookings', ['id' => $booking->id]);

        Sanctum::actingAs($owner);

        $this->deleteJson('/api/bookings/'.$booking->id)
            ->assertStatus(204);

        $this->assertDatabaseMissing('bookings', ['id' => $booking->id]);
    }

    private function toUtc(string $localIso): string
    {
        return Carbon::parse($localIso)
            ->setTimezone('Europe/Moscow')
            ->utc()
            ->format('Y-m-d H:i:s');
    }
}
