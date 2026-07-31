<?php

/**
 * Early override for Illuminate\Database\Eloquent\ModelNotFoundException.
 *
 * Vendor setModel() does implode(', ', $ids). When Livewire/route binding passes
 * a nested array as the missing id (setModel($class, [$arrayValue])), PHP throws
 * ErrorException "Array to string conversion" and hides the real model/id.
 *
 * Load this file BEFORE the vendor class is autoloaded (see bootstrap/app.php).
 */

namespace Illuminate\Database\Eloquent;

use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Support\Arr;
use Stringable;

class ModelNotFoundException extends RecordsNotFoundException
{
    /**
     * @var class-string<Model>
     */
    protected $model;

    /**
     * @var array<int, mixed>
     */
    protected $ids = [];

    /**
     * @param  class-string<Model>  $model
     * @param  array<int, mixed>|int|string  $ids
     * @return $this
     */
    public function setModel($model, $ids = [])
    {
        $this->model = $model;
        $this->ids = Arr::wrap($ids);

        $this->message = "No query results for model [{$model}]";

        if (count($this->ids) > 0) {
            $safe = array_map(static function (mixed $id): string {
                if (is_string($id) || is_int($id) || is_float($id)) {
                    return (string) $id;
                }

                if ($id instanceof Stringable) {
                    return (string) $id;
                }

                $encoded = json_encode($id, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

                return is_string($encoded) ? $encoded : 'complex_id';
            }, $this->ids);

            $this->message .= ' '.implode(', ', $safe);
        } else {
            $this->message .= '.';
        }

        return $this;
    }

    /**
     * @return class-string<Model>
     */
    public function getModel()
    {
        return $this->model;
    }

    /**
     * @return array<int, mixed>
     */
    public function getIds()
    {
        return $this->ids;
    }
}
