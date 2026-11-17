<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CalendarRequest extends OperationalRequest
{
    public function rules(): array
    {
        $kind = $this->route('kind');
        $common = ['resource_public_id' => [$kind === 'blocks' ? 'required' : 'nullable', $this->tenantExists('resources')]];
        if ($kind === 'blocks') {
            return [...$common, 'starts_at' => ['required', 'date_format:Y-m-d\TH:i:sP'], 'ends_at' => ['required', 'date_format:Y-m-d\TH:i:sP', 'after:starts_at'], 'reason' => ['required', Rule::in(['maintenance', 'leave', 'administrative', 'blackout'])], 'notes' => ['nullable', 'string', 'max:5000']];
        }
        $closed = $kind === 'overrides' && $this->boolean('closed');

        return [...$common, 'timezone' => ['required', 'timezone'], 'opens_at' => [$closed ? 'nullable' : 'required', 'date_format:H:i'], 'closes_at' => [$closed ? 'nullable' : 'required', 'date_format:H:i'], 'ends_next_day' => ['required', 'boolean'], ...($kind === 'weekly' ? ['weekday' => ['required', 'integer', 'between:0,6'], 'active' => ['required', 'boolean']] : ['date' => ['required', 'date_format:Y-m-d'], 'closed' => ['required', 'boolean'], 'reason' => ['nullable', 'string', 'max:255']])];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->route('kind') === 'blocks' || $this->boolean('closed')) {
                return;
            }
            $open = $this->input('opens_at');
            $close = $this->input('closes_at');
            if (is_string($open) && is_string($close) && ($this->boolean('ends_next_day') ? $close > $open : $close <= $open)) {
                $validator->errors()->add('closes_at', 'Use a later closing time, or mark an overnight window whose closing time is no later than its opening time.');
            }
        }];
    }
}
