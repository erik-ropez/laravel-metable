<?php

namespace Plank\Metable;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Query\JoinClause;

trait Metable
{
    /**
     * Relationship to the `Meta` model.
     * @return MorphMany
     */
    public function meta() : MorphMany
    {
        $class = config('metable.model', Meta::class);
        return $this->morphMany($class, 'metable');
    }

    /**
     * Add or update the value of the `Meta` at a given key.
     * @param string $key
     * @param mixed $value
     */
    public function setMeta(string $key, $value)
    {
        $key = strtolower($key);

        if ($this->hasMeta($key)) {
            $meta = $this->getMetaRecord($key);
            $meta->value = $value;
            $meta->save();
        } else {
            $meta = $this->makeMeta($key, $value);
            $this->meta()->save($meta);
        }

        //update cached relationship, if necessary
        if ($this->relationLoaded('meta')) {
            $this->meta[$key] = $meta;
        }
    }

    /**
     * Replace all associated `Meta` with the keys and values provided.
     * @param  array|traversable $array
     * @return void
     */
    public function syncMeta($array)
    {
        $meta = [];

        foreach ($array as $key => $value) {
            $meta[] = $this->makeMeta($key, $value);
        }

        $this->meta()->delete();
        $this->meta()->saveMany($meta);

        //update cached relationship
        $this->setRelation('meta', $this->newCollection($meta));
    }

    /**
     * Retrieve the value of the `Meta` at a given key.
     * @param  string $key
     * @param  mixed $default Fallback value if no Meta is found.
     * @return mixed
     */
    public function getMeta(string $key, $default = null)
    {
        if( $this->hasMeta($key)) {
            return $this->getMetaRecord($key)->getAttribute('value');
        }
        return $default;
    }

    /**
     * Check if a `Meta` has been set at a given key.
     * @param  string  $key
     * @return boolean
     */
    public function hasMeta(string $key) : bool
    {
        return $this->meta->has($key);
    }

    /**
     * Delete the `Meta` at a given key.
     * @param  string $key
     * @return void
     */
    public function removeMeta(string $key)
    {
        $this->getMetaRecord($key)->delete();
        $this->meta->forget($key);
    }

    /**
     * Retrieve the `Meta` model instance attached to a given key.
     * @param  string $key
     * @return Meta|null
     */
    public function getMetaRecord(string $key)
    {
        return $this->meta->get($key);
    }

    /**
     * Query scope to restrict the query to records which have `Meta` attached to a given key.
     *
     * If an array of keys is passed instead, will restrict the query to records having one or more Meta with any of the keys.
     *
     * @param  Builder $q
     * @param  string|array $key
     * @return void
     */
    public function scopeWhereHasMeta(Builder $q, $key)
    {
        $q->whereHas('meta', function(Builder $q) use($key){
            $q->whereIn('key', (array) $key);
        });
    }

    /**
     * Query scope to restrict the query to records which have `Meta` for all of the provided keys.
     * @param  Builder $q
     * @param  array   $keys
     * @return void
     */
    public function scopeWhereHasMetaKeys(Builder $q, array $keys)
    {
        $q->whereHas('meta', function(Builder $q) use($keys){
            $q->whereIn('key', $keys);
        }, '=', count($keys));
    }

    /**
     * Query scope to restrict the query to records which have `Meta` with a specific key and value.
     *
     * If the `$value` parameter is omitted, the $operator parameter will be considered the value.
     *
     * Values will be serialized to a string before comparison. If using the `>`, `>=`, `<`, or `<=` comparison operators, note that the value will be compared as a string. If comparing numeric values, use `Metable::scopeWhereMetaNumeric()` instead.
     * @param  Builder $q
     * @param  string  $key
     * @param  mixed  $operator
     * @param  mixed  $value
     * @return void
     */
    public function scopeWhereMeta(Builder $q, string $key, $operator, $value = null)
    {
        // shift arguments if no operator present
        if (!isset($value)) {
            $value = $operator;
            $operator = '=';
        }

        // convert value to its serialized version for comparison
        if (!is_string($value)) {
            $value = $this->makeMeta($key, $value)->getRawValue();
        }

        $q->whereHas('meta', function(Builder $q) use($key, $operator, $value){
            $q->where('key', $key);
            $q->where('value', $operator, $value);
        });
    }

    /**
     * Query scope to restrict the query to records which have `Meta` with a specific key and numeric value.
     *
     * Performs numeric comparison instead of string comparison.
     * @param  Builder $q
     * @param  string  $key
     * @param  string  $operator
     * @param  number  $value
     * @return void
     */
    public function scopeWhereMetaNumeric(Builder $q, string $key, string $operator, $value)
    {
        //since we are manually interpolating into the query, escape the operator to protect against injection
        $validOperators = ['<', '<=', '>', '>=', '=', '<>', '!='];
        $operator = in_array($operator, $validOperators) ? $operator : '=';

        $q->whereHas('meta', function(Builder $q) use($key, $operator, $value){
            $q->where('key', $key);
            $q->whereRaw("cast(value as numeric) {$operator} ?", [(float)$value]);
        });
    }

    /**
     * Query scope to restrict the query to records which have `Meta` with a specific key and a value within a specified set of options.
     * @param  Builder $q
     * @param  string  $key
     * @param  array   $values
     * @return void
     */
    public function scopeWhereMetaIn(Builder $q, string $key, array $values)
    {
        $values = array_map(function($val){
            return is_string($val) ? $val :$this->makeMeta($key, $values)->getRawValue();
        }, $values);

        $q->whereHas('meta', function(Builder $q) use($key, $values){
            $q->where('key', $key);
            $q->whereIn('value', $values);
        });
    }

    /**
     * Query scope to order the query results by the string value of an attached meta.
     * @param  Builder $q
     * @param  string  $key
     * @param  string  $direction
     * @return void
     */
    public function scopeOrderByMeta(Builder $q, string $key, string $direction = 'asc')
    {
        $this->joinMetaTable($q, $key);
        $q->orderBy($this->meta()->getRelated()->getTable().'.value', $direction);
    }

    /**
     * Query scope to order the query results by the numeric value of an attached meta.
     * @param  Builder $q
     * @param  string  $key
     * @param  string  $direction
     * @return void
     */
    public function scopeOrderByMetaNumeric(Builder $q, string $key, string $direction = 'asc')
    {
        $direction = strtolower($direction) == 'asc' ? 'asc' : 'desc';
        $grammar = $q->getConnection()->getQueryGrammar();
        $field = $grammar->wrap($this->meta()->getRelated()->getTable().'.value');

        $this->joinMetaTable($q, $key);
        $q->orderByRaw("cast({$field} as numeric) $direction");
    }

    /**
     * Join the meta table to the query
     * @param  Builder $q
     * @param  string  $key
     * @param  string  $type Join type
     * @return void
     */
    private function joinMetaTable(Builder $q, string $key, $type = 'left')
    {
    	$relation = $this->meta();

    	// If no explicit select columns are specified,
        // avoid column collision by excluding meta table from select
        if (!$q->getQuery()->columns) {
            $q->select($this->getTable().'.*');
        }

        // Join the meta table to the query
        $q->join($relation->getRelated()->getTable(), function(JoinClause $q) use($relation, $key) {
            $q->on($relation->getQualifiedParentKeyName(), $relation->getForeignKey())
        	    ->on($relation->getMorphType(), get_class($this))
        	    ->on($relation->getRelated()->getTable().'.key', $key);
        }, null, null, $type);
    }




    /**
     * {@InheritDoc}
     */
    public function setRelation($relation, $value)
    {
        if ($relation == 'meta') {
            // keep the meta relation indexed by key
            $value = $value->keyBy('key');
        }
        return parent::setRelation($relation, $value);
    }


    /**
     * Create a new `Meta` record.
     * @param  string $key
     * @param  mixed $value
     * @return Meta
     */
    private function makeMeta(string $key, $value) : Meta
    {
        $class = config('metable.model', Meta::class);

        $meta = new $class([
            'key' => $key,
            'value' => $value
        ]);
        return $meta;
    }

}