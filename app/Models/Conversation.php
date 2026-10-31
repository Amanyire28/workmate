<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A chat between a customer and a provider. Only the customer and the
 * provider's members may read or write it.
 */
class Conversation extends Model
{
    protected $guarded = ['*'];

    protected $casts = [
        'customer_id' => 'integer',
        'provider_profile_id' => 'integer',
        'last_message_at' => 'datetime',
    ];

    public function customer()
    {
        return $this->belongsTo(User::class, 'customer_id')->withTrashed();
    }

    public function provider()
    {
        return $this->belongsTo(ProviderProfile::class, 'provider_profile_id');
    }

    public function messages()
    {
        return $this->hasMany(Message::class);
    }

    public function latestMessage()
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }

    /** Whether the user takes part as the customer */
    public function isCustomer(User $user): bool
    {
        return $this->customer_id === (int) $user->getKey();
    }

    public function hasParticipant(User $user): bool
    {
        return $this->isCustomer($user) || $this->provider->hasMember($user);
    }

    /** Conversations the user takes part in, on either side */
    public function scopeForParticipant($query, User $user)
    {
        return $query->where(function ($q) use ($user) {
            $q->where('customer_id', $user->getKey())
                ->orWhereIn('provider_profile_id', ProviderMember::where('user_id', $user->getKey())->select('provider_profile_id'));
        });
    }
}
