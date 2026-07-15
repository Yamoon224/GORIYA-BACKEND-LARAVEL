<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Concerns\HasUuid;
use App\Enums\CommunityType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Community extends Model
{
    use Auditable, HasFactory, HasUuid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'description',
        'type',
    ];

    protected function casts(): array
    {
        return [
            'type' => CommunityType::class,
        ];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(CommunityMembership::class);
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }
}
