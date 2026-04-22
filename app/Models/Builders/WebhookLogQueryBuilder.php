<?php

namespace App\Models\Builders;

use App\Enums\WebhookStatus;
use Illuminate\Database\Eloquent\Builder;

class WebhookLogQueryBuilder extends Builder
{
    /**
     * Scope a query to only include pending webhooks.
     */
    public function pending(): self
    {
        return $this->where('status', WebhookStatus::Pending);
    }

    /**
     * Scope a query to only include processed webhooks.
     */
    public function processed(): self
    {
        return $this->where('status', WebhookStatus::Processed);
    }

    /**
     * Scope a query to only include failed webhooks.
     */
    public function failed(): self
    {
        return $this->where('status', WebhookStatus::Failed);
    }

    /**
     * Scope a query to filter by event type.
     */
    public function byEvent(string $event): self
    {
        return $this->where('event', $event);
    }

    /**
     * Scope a query to filter by business reference.
     */
    public function byBusiness(int $businessReference): self
    {
        return $this->where('business_reference', $businessReference);
    }

    /**
     * Scope a query to filter by order ID.
     */
    public function byOrder(string $orderId): self
    {
        return $this->where('order_id', $orderId);
    }

    public function byUser(int $userId): self
    {
        return $this->where('user_id', $userId);
    }
}
