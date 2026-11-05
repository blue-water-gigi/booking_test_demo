<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Room;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class BookingService
{
    public function __construct(private BookingGuard $bookingGuard) {}

    /** @param array{
     *     room_id: int,
     *     title: string,
     *     starts_at: string,
     *     ends_at: string,
     *     } $data
     *
     * @throws Throwable
     */
    public function create(array $data, int $userId): Booking
    {
        return DB::transaction(function () use ($data, $userId) {
            $room = Room::whereKey((int) $data['room_id'])
                ->lockForUpdate()
                ->firstOrFail();

            $this->bookingGuard->ensureValidDate(
                $data['starts_at'],
                $data['ends_at'],
                $room->office->timezone,
                $room->getKey()
            );

            $model = Booking::create([
                'room_id' => $room->getKey(),
                'user_id' => $userId,
                'title' => $data['title'],
                'starts_at' => $this->bookingGuard->parseDate($data['starts_at'], $room->office->timezone),
                'ends_at' => $this->bookingGuard->parseDate($data['ends_at'], $room->office->timezone),
            ]);

            Log::withContext(['booking_id' => $model->id]);
            Log::channel('bookings')->info('Booking created');

            return $model->load(['room.office', 'user']);
        });
    }
}
