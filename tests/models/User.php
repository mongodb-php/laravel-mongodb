<?php

declare(strict_types=1);

use Carbon\Carbon;
use Illuminate\Auth\Authenticatable;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Jenssegers\Mongodb\Eloquent\HybridRelations;
use Jenssegers\Mongodb\Eloquent\Model as Eloquent;
use Jenssegers\Mongodb\Relations\EmbedsMany;
use Jenssegers\Mongodb\Relations\EmbedsOne;

/**
 * Class User.
 *
 * @property string $_id
 * @property string $name
 * @property string $email
 * @property string $title
 * @property int $age
 * @property Carbon $birthday
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property string $username
 * @property MemberStatus member_status
 */
class User extends Eloquent implements AuthenticatableContract, CanResetPasswordContract
{
    use Authenticatable;
    use CanResetPassword;
    use HybridRelations;
    use Notifiable;

    protected $connection = 'mongodb';
    protected $casts = [
        'birthday' => 'datetime',
        'entry.date' => 'datetime',
        'member_status' => MemberStatus::class,
    ];
    protected static $unguarded = true;

    public function books()
    {
        return $this->hasMany('Book', 'author_id');
    }

    public function mysqlBooks()
    {
        return $this->hasMany('MysqlBook', 'author_id');
    }

    public function items()
    {
        return $this->hasMany('Item');
    }

    public function role()
    {
        return $this->hasOne('Role');
    }

    public function mysqlRole()
    {
        return $this->hasOne('MysqlRole');
    }

    public function clients()
    {
        return $this->belongsToMany('Client');
    }

    public function groups()
    {
        return $this->belongsToMany('Group');
    }

    public function photos()
    {
        return $this->morphMany('Photo', 'has_image');
    }

    public function addresses(): EmbedsMany
    {
        return $this->embedsMany('Address');
    }

    public function father(): EmbedsOne
    {
        return $this->embedsOne('User');
    }

    protected function serializeDate(DateTimeInterface $date)
    {
        return $date->format('l jS \of F Y h:i:s A');
    }

    protected function username(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value,
            set: fn ($value) => Str::slug($value)
        );
    }

    public function getFooAttribute()
    {
        return 'normal attribute';
    }

    public function foo()
    {
        return 'normal function';
    }
}
