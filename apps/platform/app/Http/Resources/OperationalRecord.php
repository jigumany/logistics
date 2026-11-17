<?php

namespace App\Http\Resources;

use Illuminate\Database\Eloquent\Model;

final class OperationalRecord
{
    public static function make(Model $model): array
    {
        $data = $model->attributesToArray();
        if (array_key_exists('photo_path', $data)) {
            $data['has_photo'] = $data['photo_path'] !== null;
            unset($data['photo_path']);
        }
        foreach (array_keys($data) as $key) {
            if ($key === 'id' || $key === 'geometry' || $key === 'path' || str_ends_with($key, '_id') && $key !== 'public_id') {
                unset($data[$key]);
            }
        }
        if ($model->relationLoaded('depot')) {
            $data['depot_public_id'] = $model->getRelation('depot')?->public_id;
            $data['depot_name'] = $model->getRelation('depot')?->name;
        }
        if ($model->relationLoaded('type')) {
            $data['vehicle_type_key'] = $model->getRelation('type')?->key;
            $data['vehicle_type_name'] = $model->getRelation('type')?->getAttribute('name');
        }
        if ($model->relationLoaded('user')) {
            $data['user_public_id'] = $model->getRelation('user')?->public_id;
        }
        if ($model->relationLoaded('resource')) {
            $data['resource_public_id'] = $model->getRelation('resource')?->public_id;
            $data['resource_name'] = $model->getRelation('resource')?->label;
        }
        if ($model->relationLoaded('eligibleClasses')) {
            $data['eligible_vehicle_classes'] = $model->getRelation('eligibleClasses')->pluck('vehicle_class')->all();
        }
        if ($model->relationLoaded('definition')) {
            $data['system_key'] = $model->getRelation('definition')?->key;
        }
        if (isset($data['opens_at'])) {
            $data['opens_at'] = substr($data['opens_at'], 0, 5);
            $data['closes_at'] = substr($data['closes_at'], 0, 5);
        }
        if (isset($data['date'])) {
            $data['date'] = substr($data['date'], 0, 10);
        }

        return $data;
    }
}
