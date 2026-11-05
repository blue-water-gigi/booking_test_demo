<?php

namespace App\Services;

use App\Models\Booking;
use Carbon\Carbon;
use Illuminate\Http\Exceptions\HttpResponseException;

class BookingGuard
{
    /**
     * @throws HttpResponseException
     */
    public function ensureValidDate(string $startsAt, string $endsAt, string $timezone, int $roomId): void
    {
        $startUtc = $this->parseDate($startsAt, $timezone);
        $endUtc = $this->parseDate($endsAt, $timezone);

        $conflict = Booking::where('room_id', $roomId)
            ->where('starts_at', '<', $endUtc)
            ->where('ends_at', '>', $startUtc)
            ->exists();

        if ($conflict) {
            throw new HttpResponseException(
                response()->json(['error' => 'Комната занята'], 409)
            );
        }
    }

    public function parseDate(string $date, string $timezone): string
    {
        return Carbon::parse($date)
            ->setTimezone($timezone)
            ->utc()
            ->format('Y-m-d H:i:s');
    }
}
