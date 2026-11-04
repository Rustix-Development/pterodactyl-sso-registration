<?php

namespace RustixDev\Registration\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pterodactyl\Models\User;

/**
 * An account someone created for themselves. Accounts an admin creates have no row.
 *
 * @property int $user_id
 * @property string $source
 * @property bool $random_password
 * @property \Carbon\CarbonImmutable|null $terms_accepted_at
 * @property \Carbon\CarbonImmutable|null $verified_at
 * @property User $user
 */
class Signup extends Model
{
    public const string SOURCE_PASSWORD = 'password';

    protected $table = 'ext_registration_signups';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $fillable = ['user_id', 'source', 'random_password', 'terms_accepted_at', 'verified_at'];

    protected $casts = [
        'random_password' => 'boolean',
        'terms_accepted_at' => 'immutable_datetime',
        'verified_at' => 'immutable_datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function sso(string $provider): string
    {
        return 'sso:'.$provider;
    }
}
