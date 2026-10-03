<?php

namespace App\Models;

use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name'])]
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'automations_enabled' => true,
        'automatic_email_enabled' => false,
        'require_approval_for_email' => true,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'automations_enabled' => 'boolean',
            'automatic_email_enabled' => 'boolean',
            'require_approval_for_email' => 'boolean',
        ];
    }

    /**
     * Automations may send email to customers without a person approving each one.
     */
    public function allowsUnattendedAutomatedEmail(): bool
    {
        return $this->automations_enabled && $this->automatic_email_enabled && ! $this->require_approval_for_email;
    }

    /**
     * @return HasMany<EmailConnection, $this>
     */
    public function emailConnections(): HasMany
    {
        return $this->hasMany(EmailConnection::class);
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * @return HasMany<Customer, $this>
     */
    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    /**
     * @return HasMany<Conversation, $this>
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /**
     * @return HasMany<Automation, $this>
     */
    public function automations(): HasMany
    {
        return $this->hasMany(Automation::class);
    }

    /**
     * @return HasMany<AutomationRun, $this>
     */
    public function automationRuns(): HasMany
    {
        return $this->hasMany(AutomationRun::class);
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * @return HasMany<CustomerTag, $this>
     */
    public function customerTags(): HasMany
    {
        return $this->hasMany(CustomerTag::class);
    }
}
