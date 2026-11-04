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
            'room_id' => ['required', 'int', Rule::exists('rooms', 'id')],
            'title' => ['required', 'string', 'max:255'],
            'starts_at' => ['required', 'date', Rule::regex($this->iso8601Regex())],
            'ends_at' => ['required', 'string', Rule::regex($this->iso8601Regex()), 'after:starts_at'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (!$this->filled(['room_id', 'starts_at', 'ends_at'])) {
                return;
            }

            $room = Room::find($this->room_id);

            if (!$room) {
                return;
            }

            $officeTz = $room->office?->timezone ?? config('app.timezone');

            try {
                $start = Carbon::parse($this->starts_at)->setTimezone($officeTz);
                $end = Carbon::parse($this->ends_at)->setTimezone($officeTz);
            } catch (\Throwable $th) {
                $validator->errors()->add('starts_at', 'Некорректный формат даты. Используй ISO 8601 с часовым поясом');
                $validator->errors()->add('ends_at', 'Некорректный формат даты. Используй ISO 8601 с часовым поясом');
                return;
            }

            //check if we are in the working date of certain office
            if ($start->toDateString() !== $end->toDateString()) {
                $validator->errors()->add('starts_at', 'Бронь не находится в пределах рабочего дня офиса');
                $validator->errors()->add('ends_at', 'Бронь не находится в пределах рабочего дня офиса');
                return;
            }

            $duration = $start->diffInMinutes($end);

            //check step
            if ($duration < 15 || $duration > 240 || $duration % 15 !== 0) {
                $validator->errors()->add(
                    'starts_at',
                    'Длительность брони должна быть от 15 до 240 минут и кратна 15 минутам'
                );
                $validator->errors()->add(
                    'ends_at',
                    'Длительность брони должна быть от 15 до 240 минут и кратна 15 минутам'
                );
            }
        });
    }

    private function iso8601Regex(): string
    {
        return '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/';
    }
}
