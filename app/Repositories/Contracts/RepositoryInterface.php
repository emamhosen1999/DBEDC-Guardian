<?php

namespace App\Repositories\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

interface RepositoryInterface
{
    /**
     * Get all records with optional filters
     *
     * @param array $filters
     * @return Collection
     */
    public function all(array $filters = []): Collection;

    /**
     * Find a record by ID
     *
     * @param int $id
     * @return Model|null
     */
    public function find(int $id): ?Model;

    /**
     * Find a record by ID or throw exception
     *
     * @param int $id
     * @return Model
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function findOrFail(int $id): Model;

    /**
     * Create a new record
     *
     * @param array $data
     * @return Model
     */
    public function create(array $data): Model;

    /**
     * Update a record
     *
     * @param int $id
     * @param array $data
     * @return Model
     */
    public function update(int $id, array $data): Model;

    /**
     * Delete a record
     *
     * @param int $id
     * @return bool
     */
    public function delete(int $id): bool;

    /**
     * Get paginated records
     *
     * @param int $perPage
     * @param array $filters
     * @return LengthAwarePaginator
     */
    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator;

    /**
     * Find records by a field value
     *
     * @param string $field
     * @param mixed $value
     * @return Collection
     */
    public function findBy(string $field, $value): Collection;

    /**
     * Find a single record by a field value
     *
     * @param string $field
     * @param mixed $value
     * @return Model|null
     */
    public function findOneBy(string $field, $value): ?Model;

    /**
     * Get query builder instance
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function query(): \Illuminate\Database\Eloquent\Builder;

    /**
     * Apply filters to query
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param array $filters
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function applyFilters(\Illuminate\Database\Eloquent\Builder $query, array $filters): \Illuminate\Database\Eloquent\Builder;
}
