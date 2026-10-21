<?php

namespace App\Domain\GameDatabase\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Validator;

abstract class Template extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::saving(function (self $template): void {
            // Validate raw scalar values before integer casts can truncate invalid input.
            $attributes = $template->getAttributes();
            foreach ($template->getCasts() as $attribute => $cast) {
                if ($cast === 'array') {
                    $attributes[$attribute] = $template->getAttribute($attribute);
                }
            }
            Validator::make($attributes, $template->rules())->validate();
        });
    }

    abstract protected function rules(): array;

    public function version(): BelongsTo
    {
        return $this->belongsTo(GameDatabaseVersion::class, 'game_database_version_id');
    }
}
