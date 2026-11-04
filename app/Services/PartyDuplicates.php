<?php

namespace App\Services;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PartyDuplicates
{
    private function name(string $value): string
    {
        $original = $value;
        $value = strtolower(Str::ascii($value));
        $value = str_replace('&', ' and ', $value);
        $value = trim(preg_replace('/[^a-z0-9]+/', ' ', $value));
        $value = preg_replace('/(?:\s+(?:limited|ltd|private|pvt|incorporated|inc|company|co))+$/', '', $value);

        return $value !== '' ? str_replace(' ', '', $value) : mb_strtolower(preg_replace('/[\s\p{P}\p{S}]+/u', '', $original));
    }

    private function phone(?string $value): string
    {
        return ltrim(preg_replace('/\D/', '', $value ?? ''), '0');
    }

    public function ensureUnique(string $model, array $input, $editing = null): void
    {
        if ($editing && trim($input['name']) === $editing->name
            && strcasecmp(trim($input['email'] ?? ''), $editing->email ?? '') === 0
            && $this->phone($input['phone'] ?? '') === $this->phone($editing->phone)) {
            return;
        }
        $name = $this->name($input['name']);
        $email = mb_strtolower(trim($input['email'] ?? ''));
        $phone = $this->phone($input['phone'] ?? '');
        foreach ($model::when($editing, fn ($q) => $q->where('id', '!=', $editing->id))->orderBy('id')->cursor() as $other) {
            $existing = $this->name($other->name);
            $distance = levenshtein($name, $existing);
            $similar = $name === $existing || (min(strlen($name), strlen($existing)) >= 6 && $distance <= max(1, (int) floor(max(strlen($name), strlen($existing)) * .08)));
            $field = $similar ? 'name' : ($email !== '' && $email === mb_strtolower(trim($other->email ?? '')) ? 'email' : ($phone !== '' && strlen($phone) >= 7 && $phone === $this->phone($other->phone) ? 'phone' : null));
            if ($field) {
                throw ValidationException::withMessages([$field => 'A matching or very similar record already exists: '.$other->name.' (ID '.$other->id.'). Open that record instead, or correct the details.']);
            }
        }
    }
}
