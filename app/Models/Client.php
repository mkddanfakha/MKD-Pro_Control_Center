<?php

namespace App\Models;

use App\Models\Installation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    protected $fillable = [
        'company_name',
        'contact_name',
        'phone',
        'email',
        'address',
        'city',
        'country',
        'status',
        'notes',
    ];

    public function installations(): HasMany
    {
        return $this->hasMany(Installation::class);
    }
}
