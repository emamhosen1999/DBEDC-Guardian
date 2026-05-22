<?php

namespace App\Repositories;

use App\Repositories\Contracts\RepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

abstract class BaseRepository implements RepositoryInterface
{
    /**
     * The model instance
     *
     * @var Model
     */
    protected Model $model;

    /**
     * Create a new repository instance
     *
     * @param Model $model
     */
    public function __construct(Model $model)
    {
        $this->model = $model;
    }

    /**
     * Get all records with optional filters
     *
     * @param array $filters
     * @return Collection
     */
    public function all(array $filters = []): Collection
    {
        $query = $this->query();
        $query = $this->applyFilters($query, $filters);
        
        return $query->get();
    }

    /**
     * Find a record by ID
     *
     * @param int $id
     * @return Model|null
     */
    public function find(int $id): ?Model
    {
        return $this->model->find($id);
    }

    /**
     * Find a record by ID or throw exception
     *
     * @param int $id
     * @return Model
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function findOrFail(int $id): Model
    {
        return $this->model->findOrFail($id);
    }

    /**
     * Create a new record
     *
     * @param array $data
     * @return Model
     */
    public function create(array $data): Model
    {
        return $this->model->create($data);
    }

    /**
     * Update a record
     *
     * @param int $id
     * @param array $data
     * @return Model
     */
    public function update(int $id, array $data): Model
    {
        $model = $this->findOrFail($id);
        $model->update($data);
        return $model->fresh();
    }

    /**
     * Delete a record
     *
     * @param int $id
     * @return bool
     */
    public function delete(int $id): bool
    {
        $model = $this->findOrFail($id);
        return $model->delete();
    }

    /**
     * Get paginated records
     *
     * @param int $perPage
     * @param array $filters
     * @return LengthAwarePaginator
     */
    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator
    {
        $query = $this->query();
        $query = $this->applyFilters($query, $filters);
        
        return $query->paginate($perPage);
    }

    /**
     * Find records by a field value
     *
     * @param string $field
     * @param mixed $value
     * @return Collection
     */
    public function findBy(string $field, $value): Collection
    {
        return $this->model->where($field, $value)->get();
    }

    /**
     * Find a single record by a field value
     *
     * @param string $field
     * @param mixed $value
     * @return Model|null
     */
    public function findOneBy(string $field, $value): ?Model
    {
        return $this->model->where($field, $value)->first();
    }

    /**
     * Get query builder instance
     *
     * @return Builder
     */
    public function query(): Builder
    {
        return $this->model->newQuery();
    }

    /**
     * Apply filters to query
     * Override in child classes to implement specific filter logic
     *
     * @param Builder $query
     * @param array $filters
     * @return Builder
     */
    public function applyFilters(Builder $query, array $filters): Builder
    {
        // Default implementation - no filters applied
        // Override in child classes
        return $query;
    }

    /**
     * Apply relationships to query
     *
     * @param Builder $query
     * @param array $relations
     * @return Builder
     */
    protected function applyRelations(Builder $query, array $relations): Builder
    {
        if (!empty($relations)) {
            return $query->with($relations);
        }
        return $query;
    }

    /**
     * Apply ordering to query
     *
     * @param Builder $query
     * @param string $column
     * @param string $direction
     * @return Builder
     */
    protected function applyOrdering(Builder $query, string $column = 'id', string $direction = 'desc'): Builder
    {
        return $query->orderBy($column, $direction);
    }

    /**
     * Apply search to query
     *
     * @param Builder $query
     * @param string $search
     * @param array $searchableFields
     * @return Builder
     */
    protected function applySearch(Builder $query, string $search, array $searchableFields): Builder
    {
        if (empty($search) || empty($searchableFields)) {
            return $query;
        }

        return $query->where(function ($q) use ($search, $searchableFields) {
            foreach ($searchableFields as $field) {
                $q->orWhere($field, 'like', "%{$search}%");
            }
        });
    }

    /**
     * Get the model instance
     *
     * @return Model
     */
    public function getModel(): Model
    {
        return $this->model;
    }

    /**
     * Set the model instance
     *
     * @param Model $model
     * @return self
     */
    public function setModel(Model $model): self
    {
        $this->model = $model;
        return $this;
    }
}
