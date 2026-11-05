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

        return DB::transaction(function () use ($data, $request) {
            $room = Room::whereKey((int)$data['room_id'])
                ->lockForUpdate()
                ->firstOrFail();

            $startUtc = Carbon::parse($data['starts_at'])
                ->setTimezone($room->office->timezone)
                ->copy()
                ->utc()
                ->format('Y-m-d H:i:s');

            $endUtc = Carbon::parse($data['starts_at'])
                ->setTimezone($room->office->timezone)
                ->copy()
                ->utc()
                ->format('Y-m-d H:i:s');

            $conflict = Booking::where('room_id', $room->getKey())
                ->where('starts_at', '<', $endUtc)
                ->where('ends_at', '>', $startUtc)
                ->exists();

            if ($conflict) {
                throw new HttpResponseException(
                    response()->json(['error' => 'Комната занята'], 409)
                );
            }

            $model = Booking::create([
                'room_id' => $room->getKey(),
                'user_id' => $request->user()->getKey(),
                'title' => $data['title'],
                'starts_at' => $startUtc,
                'ends_at' => $endUtc,
            ]);

            Log::withContext(['booking_id' => $model->id]);
            Log::channel('bookings')->info('Booking created');

            return $model->load(['room.office', 'user']);
        });
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
