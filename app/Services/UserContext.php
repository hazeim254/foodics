<?php

namespace App\Services;

use App\Models\User;

class UserContext
{
    private ?User $user = null;

    public function set(User $user): self
    {
        $this->user = $user;

        return $this;
    }

    public function get(): User
    {
        if ($this->user === null) {
            throw new \RuntimeException('UserContext has not been set. Call set() before accessing the user.');
        }

        return $this->user;
    }

    public function id(): int
    {
        return $this->get()->id;
    }

    public function flush(): void
    {
        $this->user = null;
    }
}
