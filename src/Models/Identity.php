<?php

namespace RustixDev\Registration\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pterodactyl\Models\User;

/**
 * An external account connected to a panel account.
 *
 * @property int $id
 * @property int $user_id
 * @property string $provider
 * @property string $subject
 * @property string|null $name
 * @property string|null $email
 * @property \Carbon\CarbonImmutable|null $last_used_at
 * @property \Carbon\CarbonImmutable $created_at
 */
class Identity extends Model
{
    protected $table = 'ext_registration_identities';

    protected $fillable = ['user_id', 'provider', 'subject', 'name', 'email', 'last_used_at'];

    protected $casts = [
        'last_used_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
