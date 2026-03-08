<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Submission extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'name',
        'email',
        'subject',
        'message',
        'page_url',
        'status',
    ];

    /**
     * @return BelongsTo<User, Submission>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
