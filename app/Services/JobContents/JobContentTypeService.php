<?php

namespace App\Services\JobContents;

use App\Models\JobContentType;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

final class JobContentTypeService
{
    /**
     * Pickers only see active entries; managers can ask for the whole list.
     *
     * @return Collection<int, JobContentType>
     */
    public function list(bool $includeInactive): Collection
    {
        $query = JobContentType::query()
            ->orderBy('sort_order')
            ->orderByRaw('LOWER(name)')
            ->orderBy('id');

        if (! $includeInactive) {
            $query->active();
        }

        return $query->get();
    }

    /**
     * New entries go to the end of the list unless a position is given.
     *
     * @param  array{name: string, sortOrder?: int|null, active?: bool}  $payload
     */
    public function create(User $actor, array $payload): JobContentType
    {
        try {
            $entry = JobContentType::query()->create([
                'name' => $payload['name'],
                'active' => $payload['active'] ?? true,
                'sort_order' => $payload['sortOrder'] ?? $this->nextSortOrder(),
            ]);
        } catch (UniqueConstraintViolationException) {
            throw $this->duplicateName();
        }

        Log::info('Job contents entry created.', [
            'job_content_type_id' => $entry->id,
            'name' => $entry->name,
            'user_id' => $actor->id,
        ]);

        return $entry;
    }

    /**
     * Rename, retire/reactivate or reorder. Jobs keep the text they were
     * created with, so nothing else needs updating.
     *
     * @param  array{name?: string, active?: bool, sortOrder?: int}  $payload
     */
    public function update(User $actor, JobContentType $entry, array $payload): JobContentType
    {
        if (array_key_exists('name', $payload)) {
            $entry->name = $payload['name'];
        }

        if (array_key_exists('active', $payload)) {
            $entry->active = $payload['active'];
        }

        if (array_key_exists('sortOrder', $payload)) {
            $entry->sort_order = $payload['sortOrder'];
        }

        try {
            $entry->save();
        } catch (UniqueConstraintViolationException) {
            throw $this->duplicateName();
        }

        Log::info('Job contents entry updated.', [
            'job_content_type_id' => $entry->id,
            'name' => $entry->name,
            'active' => $entry->active,
            'user_id' => $actor->id,
        ]);

        return $entry;
    }

    private function nextSortOrder(): int
    {
        return ((int) JobContentType::query()->max('sort_order')) + 1;
    }

    private function duplicateName(): ValidationException
    {
        return ValidationException::withMessages([
            'name' => ['There is already a contents entry with that name.'],
        ]);
    }
}
