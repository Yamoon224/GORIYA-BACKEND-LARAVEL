<?php

namespace App\Models;

use App\Concerns\HasUuid;
use App\Enums\EventStatus;
use App\Enums\EventType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CalendarEvent extends Model
{
    use HasFactory, HasUuid;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'title',
        'type',
        'start_time',
        'end_time',
        'participants',
        'location',
        'status',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => EventType::class,
            'status' => EventStatus::class,
            'start_time' => 'datetime',
            'end_time' => 'datetime',
            'participants' => 'array',
        ];
    }
}
