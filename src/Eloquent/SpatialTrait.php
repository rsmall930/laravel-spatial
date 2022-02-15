<?php

namespace LaravelSpatial\Eloquent;

use Exception;
use GeoJson\GeoJson;
use GeoJSON\Geometry\Geometry;
use geoPHP\geoPHP;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;
use LaravelSpatial\Exceptions\SpatialFieldsNotDefinedException;
use LaravelSpatial\Exceptions\SpatialParseException;
use LaravelSpatial\Exceptions\UnknownSpatialRelationFunction;

/**
 * Trait SpatialTrait.
 *
 * @method static static|EloquentBuilder distance($geometryColumn, $geometry, $distance)
 * @method static static|EloquentBuilder distanceValue($geometryColumn, $geometry)
 * @method static static|EloquentBuilder distanceExcludingSelf($geometryColumn, $geometry, $distance)
 * @method static static|EloquentBuilder distanceSphere($geometryColumn, $geometry, $distance)
 * @method static static|EloquentBuilder distanceSphereValue($geometryColumn, $geometry)
 * @method static static|EloquentBuilder distanceSphereExcludingSelf($geometryColumn, $geometry, $distance)
 * @method static static|EloquentBuilder comparison($geometryColumn, $geometry, $relationship)
 * @method static static|EloquentBuilder within($geometryColumn, $polygon)
 * @method static static|EloquentBuilder crosses($geometryColumn, $geometry)
 * @method static static|EloquentBuilder contains($geometryColumn, $geometry)
 * @method static static|EloquentBuilder disjoint($geometryColumn, $geometry)
 * @method static static|EloquentBuilder equals($geometryColumn, $geometry)
 * @method static static|EloquentBuilder intersects($geometryColumn, $geometry)
 * @method static static|EloquentBuilder overlaps($geometryColumn, $geometry)
 * @method static static|EloquentBuilder doesTouch($geometryColumn, $geometry)
 */
trait SpatialTrait
{
    /*
     * The attributes that are spatial representations.
     * To use this Trait, add the following array to the model class
     *
     * @var array
     *
     * protected $spatialFields = [];
     */

    /**
     * @var array
     */
    public $geometries = [];

    /**
     * @var array
     */
    protected $stRelations = [
        'Within',
        'Crosses',
        'Contains',
        'Disjoint',
        'Equals',
        'Intersects',
        'Overlaps',
        'Touches',
    ];

    /**
     * Create a new Eloquent query builder for the model.
     *
     * @param  \Illuminate\Database\Query\Builder $query
     *
     * @return \LaravelSpatial\Eloquent\Builder
     */
    public function newEloquentBuilder($query)
    {
        return new Builder($query);
    }

    /**
     * @inheritDoc
     */
    public function setRawAttributes(array $attributes, $sync = false)
    {
        $spatial_fields = $this->getSpatialFields();

        foreach ($attributes as $attribute => &$value) {
            if (is_string($value) && in_array($attribute, $spatial_fields, true) && strlen($value) >= 15) {
                $connection = $this->getConnection();

                // MySQL adds 4 NULL bytes at the start of the binary
                if ($connection instanceof MySqlConnection && strpos($value, "\0\0\0\0") === 0) {
                    $value = substr($value, 4);
                } elseif ($connection instanceof PostgresConnection) {
                    $value = pack('H*', $value);
                }

                try {
                    $value = GeoJson::jsonUnserialize(
                        json_decode(
                            geoPHP::load($value, 'wkb')
                                  ->out('json'),
                            false,
                            512,
                            JSON_THROW_ON_ERROR
                        )
                    );
                } catch (Exception $e) {
                    throw new SpatialParseException("Can't parse WKB {$value}: {$e->getMessage()}", $e->getCode(), $e);
                }
            }
        }

        return parent::setRawAttributes($attributes, $sync);
    }

    /**
     * @return array
     */
    public function getSpatialFields(): array
    {
        if (property_exists($this, 'spatialFields') && !empty($this->spatialFields)) {
            return $this->spatialFields;
        }

        throw new SpatialFieldsNotDefinedException(__CLASS__ . ' has to define $spatialFields');
    }

    /**
     * @param \GeoJSON\Geometry\Geometry $value
     *
     * @return string
     */
    protected function toWkt(Geometry $value): string
    {
        try {
            $decoded = json_decode(
                json_encode($value->jsonSerialize(), JSON_THROW_ON_ERROR),
                false,
                512,
                JSON_THROW_ON_ERROR
            );
            $wkt     = geoPHP::load($decoded, 'json')->out('wkt');
        } catch (Exception $e) {
            throw new SpatialParseException('Unable to data to geometry.', 0, $e);
        }

        return ($this->getConnection() instanceof PostgresConnection ? 'SRID=4326;' : '') . $wkt;
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return bool
     */
    protected function performInsert(EloquentBuilder $query)
    {
        foreach ($this->attributes as $key => $value) {
            if ($value instanceof Geometry && $this->isColumnAllowed($key)) {
                $this->geometries[$key] = $value; // Preserve the geometry objects prior to the insert
                $this->attributes[$key] = $this->getConnection()->raw("ST_GeomFromText('{$this->toWkt($value)}')");
            }
        }

        $insert = parent::performInsert($query);

        foreach ($this->geometries as $key => $value) {
            $this->attributes[$key] = $value; // Retrieve the geometry objects so they can be used in the model
        }

        return $insert; // Return the result of the parent insert
    }

    /**
     * @param $geometryColumn
     *
     * @return bool
     */
    public function isColumnAllowed($geometryColumn): bool
    {
        if (!in_array($geometryColumn, $this->getSpatialFields(), true)) {
            throw new SpatialFieldsNotDefinedException(sprintf('%s is not a valid spatial column.', $geometryColumn));
        }

        return true;
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param $geometryColumn
     * @param \GeoJSON\Geometry\Geometry $geometry
     * @param $distance
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeDistance(
        EloquentBuilder $query,
        $geometryColumn,
        Geometry $geometry,
        $distance
    ): EloquentBuilder {
        if ($this->isColumnAllowed($geometryColumn)) {
            $geometryColumn .= $this->getConnection() instanceof PostgresConnection ? '::geometry' : '';
            $query->whereRaw("ST_Distance({$geometryColumn}, ST_GeomFromText(?)) <= ?", [
                $this->toWkt($geometry),
                $distance,
            ]);
        }

        return $query;
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param $geometryColumn
     * @param \GeoJSON\Geometry\Geometry $geometry
     * @param $distance
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeDistanceExcludingSelf(
        EloquentBuilder $query,
        $geometryColumn,
        Geometry $geometry,
        $distance
    ): EloquentBuilder {
        if ($this->isColumnAllowed($geometryColumn)) {
            $query = $this->scopeDistance($query, $geometryColumn, $geometry, $distance);

            $geometryColumn .= $this->getConnection() instanceof PostgresConnection ? '::geometry' : '';
            $query->whereRaw("ST_Distance({$geometryColumn}, ST_GeomFromText(?)) != 0", [
                $this->toWkt($geometry),
            ]);
        }

        return $query;
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param $geometryColumn
     * @param \GeoJSON\Geometry\Geometry $geometry
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeDistanceValue(EloquentBuilder $query, $geometryColumn, Geometry $geometry): EloquentBuilder
    {
        if ($this->isColumnAllowed($geometryColumn)) {
            $columns = $query->getQuery()->columns;

            if (!$columns) {
                $query->select('*');
            }

            $geometryColumn .= $this->getConnection() instanceof PostgresConnection ? '::geometry' : '';
            $query->selectRaw("ST_Distance({$geometryColumn}, ST_GeomFromText(?)) as distance", [
                $this->toWkt($geometry),
            ]);
        }

        return $query;
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param $geometryColumn
     * @param \GeoJSON\Geometry\Geometry $geometry
     * @param $distance
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeDistanceSphere(
        EloquentBuilder $query,
        $geometryColumn,
        Geometry $geometry,
        $distance
    ): EloquentBuilder {
        $distFunc = $this->getConnection() instanceof PostgresConnection ? 'ST_DistanceSphere' : 'ST_Distance_Sphere';

        if ($this->isColumnAllowed($geometryColumn)) {
            $geometryColumn .= $this->getConnection() instanceof PostgresConnection ? '::geometry' : '';
            $query->whereRaw("{$distFunc}({$geometryColumn}, ST_GeomFromText(?)) <= ?", [
                $this->toWkt($geometry),
                $distance,
            ]);
        }

        return $query;
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param $geometryColumn
     * @param \GeoJSON\Geometry\Geometry $geometry
     * @param $distance
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeDistanceSphereExcludingSelf(
        EloquentBuilder $query,
        $geometryColumn,
        Geometry $geometry,
        $distance
    ): EloquentBuilder {
        $distFunc = $this->getConnection() instanceof PostgresConnection ? 'ST_DistanceSphere' : 'ST_Distance_Sphere';

        if ($this->isColumnAllowed($geometryColumn)) {
            $query = $this->scopeDistanceSphere($query, $geometryColumn, $geometry, $distance);

            $geometryColumn .= $this->getConnection() instanceof PostgresConnection ? '::geometry' : '';
            $query->whereRaw("{$distFunc}({$geometryColumn}, ST_GeomFromText(?)) != 0", [
                $this->toWkt($geometry),
            ]);
        }

        return $query;
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param $geometryColumn
     * @param \GeoJSON\Geometry\Geometry $geometry
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeDistanceSphereValue(
        EloquentBuilder $query,
        $geometryColumn,
        Geometry $geometry
    ): EloquentBuilder {
        $distFunc = $this->getConnection() instanceof PostgresConnection ? 'ST_DistanceSphere' : 'ST_Distance_Sphere';

        if ($this->isColumnAllowed($geometryColumn)) {
            $columns = $query->getQuery()->columns;

            if (!$columns) {
                $query->select('*');
            }

            $geometryColumn .= $this->getConnection() instanceof PostgresConnection ? '::geometry' : '';
            $query->selectRaw("{$distFunc}({$geometryColumn}, ST_GeomFromText(?)) as distance", [
                $this->toWkt($geometry),
            ]);
        }

        return $query;
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param $geometryColumn
     * @param \GeoJSON\Geometry\Geometry $geometry
     * @param $relationship
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeComparison(
        EloquentBuilder $query,
        $geometryColumn,
        Geometry $geometry,
        $relationship
    ): EloquentBuilder {
        if ($this->isColumnAllowed($geometryColumn)) {
            $relationship = ucfirst(strtolower($relationship));

            if (!in_array($relationship, $this->stRelations, true)) {
                throw new UnknownSpatialRelationFunction($relationship);
            }

            $geometryColumn .= $this->getConnection() instanceof PostgresConnection ? '::geometry' : '';
            $query->whereRaw("ST_{$relationship}(`{$geometryColumn}`, ST_GeomFromText(?))", [
                $this->toWkt($geometry),
            ]);
        }

        return $query;
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param $geometryColumn
     * @param $polygon
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithin(EloquentBuilder $query, $geometryColumn, Geometry $polygon): EloquentBuilder
    {
        return $this->scopeComparison($query, $geometryColumn, $polygon, 'within');
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param $geometryColumn
     * @param \GeoJSON\Geometry\Geometry $geometry
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeCrosses(EloquentBuilder $query, $geometryColumn, Geometry $geometry): EloquentBuilder
    {
        return $this->scopeComparison($query, $geometryColumn, $geometry, 'crosses');
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param $geometryColumn
     * @param \GeoJSON\Geometry\Geometry $geometry
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeContains(EloquentBuilder $query, $geometryColumn, Geometry $geometry): EloquentBuilder
    {
        return $this->scopeComparison($query, $geometryColumn, $geometry, 'contains');
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param $geometryColumn
     * @param \GeoJSON\Geometry\Geometry $geometry
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeDisjoint(EloquentBuilder $query, $geometryColumn, Geometry $geometry): EloquentBuilder
    {
        return $this->scopeComparison($query, $geometryColumn, $geometry, 'disjoint');
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param $geometryColumn
     * @param \GeoJSON\Geometry\Geometry $geometry
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeEquals(EloquentBuilder $query, $geometryColumn, Geometry $geometry): EloquentBuilder
    {
        return $this->scopeComparison($query, $geometryColumn, $geometry, 'equals');
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param $geometryColumn
     * @param \GeoJSON\Geometry\Geometry $geometry
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeIntersects(EloquentBuilder $query, $geometryColumn, Geometry $geometry): EloquentBuilder
    {
        return $this->scopeComparison($query, $geometryColumn, $geometry, 'intersects');
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param $geometryColumn
     * @param \GeoJSON\Geometry\Geometry $geometry
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOverlaps(EloquentBuilder $query, $geometryColumn, Geometry $geometry): EloquentBuilder
    {
        return $this->scopeComparison($query, $geometryColumn, $geometry, 'overlaps');
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param $geometryColumn
     * @param \GeoJSON\Geometry\Geometry $geometry
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeDoesTouch(EloquentBuilder $query, $geometryColumn, Geometry $geometry): EloquentBuilder
    {
        return $this->scopeComparison($query, $geometryColumn, $geometry, 'touches');
    }
}
