<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBookingRequest;
use App\Models\Booking;
use App\Models\Room;
use Carbon\Carbon;
use HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class BookingController extends Controller
{
    public function index(Request $request)
    {
        return Booking::where('user_id', $request->user()->id)
            ->orderByRaw('starts_at DESC')
            ->paginate(20);
    }

    /**
     * @throws Throwable
     */
    public function store(StoreBookingRequest $request): JsonResponse
    {
        $data = $request->validated();

        $room = Room::with('office')->findOrFail($data['room_id']);

        $startLocal = Carbon::parse($data['starts_at'])->setTimezone($room->office->timezone);
        $endLocal = Carbon::parse($data['starts_at'])->setTimezone($room->office->timezone);

        $startUtc = $startLocal->copy()->utc()->format('Y-m-d H:i:s');
        $endUtc = $endLocal->copy()->utc()->format('Y-m-d H:i:s');

        $conflict = DB::transaction(function () use ($room, $startUtc, $endUtc, $data, $request) {
            $bookingExists = Booking::where('room_id', $room->id)
                ->where('starts_at', '<', $endUtc)
                ->where('ends_at', '>', $startUtc)
                ->exists();

            if ($bookingExists) {
                throw new HttpResponseException(
                    response()->json(['error' => 'Комната занята'], 409)
                );
            }
            $booking = Booking::create([
                'room_id' => $room->id,
                'user_id' => $request->user()->getKey(),
                'title' => $data['title'],
                'starts_at' => $startUtc,
                'ends_at' => $endUtc,
            ]);

            Log::withContext(['booking_id' => $booking->id]);
            Log::channel('bookings')->info('Booking created');

            return $booking;
        });

        return $conflict->load('room.office', 'user');
    }

    public function destroy(Request $request, $id): JsonResponse
    {
        $booking = Booking::findOrFail($id);

        if ($booking->user_id !== $request->user()->getKey()) {
            return response()->json([
                'error' => 'Отменить бронь может только её автор'
            ], 403);
        }

        $booking->delete();

        return response()->json(status: 204);
    }

    public function today(Room $room)
    {
        return $room->bookings()
            ->whereDate('starts_at', today())
            ->get()
            ->map(fn($b) => [
                'time' => $b->starts_at . ' – ' . $b->ends_at,
                'author' => $b->user->name,
            ]);
    }
}
