<?php

use App\Enums\SettingKey;
use App\Models\User;
use App\Models\UserSetting;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('returns null when the setting has no row', function () {
    expect($this->user->setting(SettingKey::DaftraDefaultClientId))->toBeNull();
});

it('stores a value with setSetting', function () {
    $this->user->setSetting(SettingKey::DaftraDefaultClientId, '12345');

    expect($this->user->setting(SettingKey::DaftraDefaultClientId))->toBe('12345');
    expect(UserSetting::query()
        ->where('user_id', $this->user->id)
        ->where('key', SettingKey::DaftraDefaultClientId->value)
        ->count()
    )->toBe(1);
});

it('updates an existing setting without creating a duplicate row', function () {
    $this->user->setSetting(SettingKey::DaftraDefaultClientId, '111');
    $this->user->setSetting(SettingKey::DaftraDefaultClientId, '222');

    expect($this->user->setting(SettingKey::DaftraDefaultClientId))->toBe('222');
    expect(UserSetting::query()
        ->where('user_id', $this->user->id)
        ->where('key', SettingKey::DaftraDefaultClientId->value)
        ->count()
    )->toBe(1);
});

it('stores null without deleting the row', function () {
    $this->user->setSetting(SettingKey::DaftraDefaultClientId, '111');
    $this->user->setSetting(SettingKey::DaftraDefaultClientId, null);

    expect($this->user->setting(SettingKey::DaftraDefaultClientId))->toBeNull();
    expect(UserSetting::query()
        ->where('user_id', $this->user->id)
        ->where('key', SettingKey::DaftraDefaultClientId->value)
        ->exists()
    )->toBeTrue();
});

it('removes the row with forgetSetting', function () {
    $this->user->setSetting(SettingKey::DaftraDefaultClientId, '111');
    $this->user->forgetSetting(SettingKey::DaftraDefaultClientId);

    expect(UserSetting::query()
        ->where('user_id', $this->user->id)
        ->where('key', SettingKey::DaftraDefaultClientId->value)
        ->exists()
    )->toBeFalse();
});

it('cascades deletes when the user is deleted', function () {
    $this->user->setSetting(SettingKey::DaftraDefaultClientId, '111');
    $this->user->delete();

    expect(UserSetting::query()->count())->toBe(0);
});

it('enforces uniqueness of user_id and key at the database level', function () {
    $this->user->setSetting(SettingKey::DaftraDefaultClientId, '111');

    expect(fn () => UserSetting::query()->create([
        'user_id' => $this->user->id,
        'key' => SettingKey::DaftraDefaultClientId->value,
        'value' => '222',
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('reads the setting from an eager-loaded relationship without hitting the database', function () {
    $this->user->setSetting(SettingKey::DaftraDefaultClientId, '12345');

    $user = User::query()->with('settings')->find($this->user->id);

    DB::enableQueryLog();
    $value = $user->setting(SettingKey::DaftraDefaultClientId);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($value)->toBe('12345');
    expect($queries)->toBeEmpty();
});

it('casts the key column back to a SettingKey enum', function () {
    $this->user->setSetting(SettingKey::DaftraDefaultClientId, '42');

    $row = UserSetting::query()->where('user_id', $this->user->id)->first();

    expect($row->key)->toBe(SettingKey::DaftraDefaultClientId);
});

it('scopes settings per user', function () {
    $otherUser = User::factory()->create();

    $this->user->setSetting(SettingKey::DaftraDefaultClientId, '111');
    $otherUser->setSetting(SettingKey::DaftraDefaultClientId, '222');

    expect($this->user->setting(SettingKey::DaftraDefaultClientId))->toBe('111');
    expect($otherUser->setting(SettingKey::DaftraDefaultClientId))->toBe('222');
});
