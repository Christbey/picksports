<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class ModelTrainingUpload extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['bytes' => 'integer'];
    }

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('Training uploads are immutable.');
        }

        return parent::save($options);
    }

    public function delete(): ?bool
    {
        throw new LogicException('Training upload receipts cannot be deleted.');
    }

    protected function incrementOrDecrement($column, $amount, $extra, $method)
    {
        throw new LogicException('Training uploads cannot be numerically mutated.');
    }
}
