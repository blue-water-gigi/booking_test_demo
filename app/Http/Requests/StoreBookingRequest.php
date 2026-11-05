<?php

namespace App\Http\Requests;

use App\Models\Room;
use Carbon\Carbon;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBookingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'room_id' => ['required', 'integer', Rule::exists('rooms', 'id')],
            'title' => ['required', 'string', 'max:255'],
            'starts_at' => ['required', 'string', 'regex:' . $this->iso8601Regex()],
            'ends_at' => ['required', 'string', 'regex:' . $this->iso8601Regex(), 'after:starts_at'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (!$this->filled(['room_id', 'starts_at', 'ends_at'])) {
                return;
            }

            $room = Room::with('office')->find($this->input('room_id'));

            if (!$room || !$room->office) {
                return;
            }

            $timezone = $room->office->timezone;

            try {
                $start = Carbon::parse($this->input('starts_at'))->setTimezone($timezone);
                $end = Carbon::parse($this->input('ends_at'))->setTimezone($timezone);
            } catch (\Throwable) {
                $validator->errors()->add('starts_at', 'Некорректный формат даты. Используй ISO 8601 с часовым поясом.');
                $validator->errors()->add('ends_at', 'Некорректный формат даты. Используй ISO 8601 с часовым поясом.');

                return;
            }

            $dayStart = $start->copy()->startOfDay()->setTime(9, 0, 0);
            $dayEnd = $start->copy()->startOfDay()->setTime(20, 0, 0);

            if (!$start->isSameDay($end) || $start->lt($dayStart) || $end->gt($dayEnd)) {
                $validator->errors()->add('starts_at', 'Бронь должна полностью входить в рабочее время офиса: 09:00–20:00 по местному времени.');
                $validator->errors()->add('ends_at', 'Бронь должна полностью входить в рабочее время офиса: 09:00–20:00 по местному времени.');

                return;
            }

            $durationMinutes = (int)$start->diffInMinutes($end, false);

            if ($durationMinutes < 15 || $durationMinutes > 240 || $durationMinutes % 15 !== 0) {
                $validator->errors()->add(
                    'starts_at',
                    'Длительность брони должна быть от 15 до 240 минут и кратна 15 минутам.'
                );
                $validator->errors()->add(
                    'ends_at',
                    'Длительность брони должна быть от 15 до 240 минут и кратна 15 минутам.'
                );
            }
        });
    }

    private function iso8601Regex(): string
    {
        return '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})$/';
    }
}
