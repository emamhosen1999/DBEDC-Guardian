<?php

namespace App\Repositories\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;

interface RepositoryInterface
{
    /**
     * Get all records with optional filters
     */
    public function all(array $filters = []): Collection;

    /**
     * Find a record by ID
     */
    public function find(int $id): ?Model;

    /**
     * Find a record by ID or throw exception
     *
     * @throws ModelNotFoundException
     */
    public function findOrFail(int $id): Model;

    /**
     * Create a new record
     */
    public function create(array $data): Model;

    /**
     * Update a record
     */
    public function update(int $id, array $data): Model;

    /**
     * Delete a record
     */
    public function delete(int $id): bool;

    /**
     * Get paginated records
     */
    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator;

    /**
     * Find records by a field value
     *
     * @param  mixed  $value
     */
    public function findBy(string $field, $value): Collection;

    /**
     * Find a single record by a field value
     *
     * @param  mixed  $value
     */
    public function findOneBy(string $field, $value): ?Model;

    /**
     * Get query builder instance
     */
    public function query(): Builder;

    /**
     * Apply filters to query
     */
    public function applyFilters(Builder $query, array $filters): Builder;
}
